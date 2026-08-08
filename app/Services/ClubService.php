<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Club;
use App\Models\ClubMonthlyFee;
use App\Models\ClubSubscription;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ClubService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * إنشاء نادي وتخصيص المستويات المسموح لها بدراسته.
     */
    public function createClub(array $data, array $levelIds = []): Club
    {
        return DB::transaction(function () use ($data, $levelIds) {
            $feeCategoryId = $data['fee_category_id'] ?? null;
            if (! $feeCategoryId) {
                $feeCategory = \App\Models\FeeCategory::firstOrCreate(
                    ['code' => 'CLUB'],
                    ['name' => 'معاليم النوادي', 'is_recurring' => true]
                );
                $feeCategoryId = $feeCategory->id;
            }

            $club = Club::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'fee_category_id' => $feeCategoryId,
                'monthly_fee' => $data['monthly_fee'] ?? 0.00,
                'is_active' => $data['is_active'] ?? true,
            ]);

            if ($levelIds !== []) {
                $club->levels()->sync($levelIds);
            }

            $this->syncFeeTypePrice($club);

            return $club->fresh('levels');
        });
    }

    /**
     * تعديل بيانات النادي والمستويات المسموح لها.
     */
    public function updateClub(Club $club, array $data, ?array $levelIds = null): Club
    {
        return DB::transaction(function () use ($club, $data, $levelIds) {
            $club->update($data);

            if ($levelIds !== null) {
                $club->levels()->sync($levelIds);
            }

            $this->syncFeeTypePrice($club);
            $count = $this->syncUnpaidCurrentMonthFees($club);

            $fresh = $club->fresh('levels');
            $fresh->setAttribute('updated_unpaid_count', $count);

            return $fresh;
        });
    }

    /**
     * مزامنة مبلغ معلوم النادي مع قائمة أنواع المعاليم العامة.
     */
    private function syncFeeTypePrice(Club $club): void
    {
        $normalizedClubName = \App\Models\FeeType::normalize($club->name);

        \App\Models\FeeType::all()->each(function ($feeType) use ($normalizedClubName, $club) {
            $normalizedFeeTypeName = \App\Models\FeeType::normalize($feeType->name_ar);
            if (
                $normalizedClubName === $normalizedFeeTypeName ||
                str_contains($normalizedClubName, $normalizedFeeTypeName) ||
                str_contains($normalizedFeeTypeName, $normalizedClubName) ||
                (str_contains($normalizedClubName, 'روبوت') && str_contains($normalizedFeeTypeName, 'روبوت')) ||
                (str_contains($normalizedClubName, 'حساب') && str_contains($normalizedFeeTypeName, 'حساب'))
            ) {
                $feeType->update(['price' => $club->monthly_fee]);
            }
        });
    }

    /**
     * مزامنة مبلغ معلوم النادي في السجلات الشهرية الحالية غير المدفوعة للشهر الحالي.
     * تحمي السجلات المدفوعة بالكامل أو جزئياً، والأشهر السابقة المغلقة، والحركات المطبقة في الخزينة.
     */
    private function syncUnpaidCurrentMonthFees(Club $club): int
    {
        $activeYearId = AcademicYear::where('is_active', true)->value('id');
        if (! $activeYearId) {
            return 0;
        }

        $currentMonth = now()->format('Y-m');

        return ClubMonthlyFee::where('club_id', $club->id)
            ->where('academic_year_id', $activeYearId)
            ->where('month', $currentMonth)
            ->where('status', ClubMonthlyFee::STATUS_UNPAID)
            ->where(function ($q) {
                $q->whereNull('amount_paid')->orWhere('amount_paid', '<=', 0);
            })
            ->whereNull('cancelled_at')
            ->update([
                'amount_due' => number_format((float) $club->monthly_fee, 2, '.', ''),
            ]);
    }

    /**
     * تسجيل تلميذ في نادي.
     */
    public function subscribeStudent(
        int $studentId,
        int $clubId,
        int $academicYearId,
        ?string $startDate = null,
        ?float $feeOverride = null,
        ?int $enrollmentId = null
    ): ClubSubscription {
        return DB::transaction(function () use ($studentId, $clubId, $academicYearId, $startDate, $feeOverride, $enrollmentId) {
            $club = Club::with('levels')->findOrFail($clubId);

            if (! $club->is_active) {
                throw new InvalidArgumentException('هذا النادي غير نَشِط حالياً');
            }

            if (! $enrollmentId) {
                $enrollment = Enrollment::where('student_id', $studentId)
                    ->where('academic_year_id', $academicYearId)
                    ->where('status', 'active')
                    ->first();

                if (! $enrollment) {
                    throw new InvalidArgumentException('التلميذ غير مُرسَّم في هذه السنة الدراسية');
                }
                $enrollmentId = $enrollment->id;
                $levelId = $enrollment->level_id;
            } else {
                $enrollment = Enrollment::findOrFail($enrollmentId);
                $levelId = $enrollment->level_id;
            }

            $allowedLevels = $club->levels->pluck('id')->all();
            if ($allowedLevels !== [] && ! in_array($levelId, $allowedLevels, true)) {
                throw new InvalidArgumentException('هذا النادي غير متاح لمستوى التلميذ المحدَّد');
            }

            $subscription = ClubSubscription::updateOrCreate(
                [
                    'student_id' => $studentId,
                    'club_id' => $clubId,
                    'academic_year_id' => $academicYearId,
                ],
                [
                    'enrollment_id' => $enrollmentId,
                    'start_date' => $startDate ?? now()->toDateString(),
                    'status' => 'active',
                    'monthly_fee_override' => $feeOverride,
                    'excluded_at' => null,
                    'excluded_by' => null,
                    'exclusion_reason' => null,
                ]
            );

            return $subscription->fresh(['student', 'club', 'academicYear']);
        });
    }

    /**
     * استبعاد تلميذ من متابعة معلوم النادي بعد سبتمبر دون حذف التلميذ أو مدفوعاته القديمة.
     */
    public function excludeStudent(ClubSubscription $subscription, int $userId, ?string $reason = null): ClubSubscription
    {
        return DB::transaction(function () use ($subscription, $userId, $reason) {
            $subscription->update([
                'status' => 'cancelled',
                'excluded_at' => now(),
                'excluded_by' => $userId,
                'exclusion_reason' => $reason,
                'end_date' => now()->toDateString(),
            ]);

            return $subscription->fresh();
        });
    }

    /**
     * إلغاء استبعاد التلميذ وإعادته لمتابعة النادي.
     */
    public function restoreStudent(ClubSubscription $subscription): ClubSubscription
    {
        return DB::transaction(function () use ($subscription) {
            $subscription->update([
                'status' => 'active',
                'excluded_at' => null,
                'excluded_by' => null,
                'exclusion_reason' => null,
                'end_date' => null,
            ]);

            return $subscription->fresh();
        });
    }

    /**
     * توليد سجلات الشهر لتلاميذ النوادي المسجلين وغير المستبعدين.
     */
    public function generateMonthFees(
        int $academicYearId,
        string $month,
        ?int $clubId = null,
        ?int $sectionId = null,
        ?int $userId = null
    ): array {
        return DB::transaction(function () use ($academicYearId, $month, $clubId, $sectionId, $userId) {
            $createdCount = 0;
            $skippedCount = 0;

            if ($clubId) {
                $club = Club::with('levels')->findOrFail($clubId);
                if (! $club->is_active) {
                    throw new InvalidArgumentException('هذا النادي غير نَشِط حالياً');
                }

                $allowedLevels = $club->levels->pluck('id')->all();

                $enrollmentsQuery = Enrollment::where('academic_year_id', $academicYearId)
                    ->where('status', 'active');

                if ($sectionId) {
                    $enrollmentsQuery->where('section_id', $sectionId);
                }

                if ($allowedLevels !== []) {
                    $enrollmentsQuery->whereIn('level_id', $allowedLevels);
                }

                $enrollments = $enrollmentsQuery->get();

                foreach ($enrollments as $enr) {
                    $sub = ClubSubscription::where('student_id', $enr->student_id)
                        ->where('club_id', $clubId)
                        ->where('academic_year_id', $academicYearId)
                        ->first();

                    if ($sub && $sub->excluded_at !== null) {
                        continue;
                    }

                    if (! $sub) {
                        $sub = ClubSubscription::create([
                            'student_id' => $enr->student_id,
                            'club_id' => $clubId,
                            'academic_year_id' => $academicYearId,
                            'enrollment_id' => $enr->id,
                            'start_date' => now()->toDateString(),
                            'status' => 'active',
                        ]);
                    }

                    $amountDue = $sub->monthly_fee_override !== null
                        ? (float) $sub->monthly_fee_override
                        : (float) $club->monthly_fee;

                    $fee = ClubMonthlyFee::where('student_id', $enr->student_id)
                        ->where('club_id', $clubId)
                        ->where('month', $month)
                        ->where('academic_year_id', $academicYearId)
                        ->first();

                    if ($fee) {
                        $skippedCount++;
                        continue;
                    }

                    ClubMonthlyFee::create([
                        'student_id' => $enr->student_id,
                        'club_id' => $clubId,
                        'academic_year_id' => $academicYearId,
                        'enrollment_id' => $enr->id,
                        'club_subscription_id' => $sub->id,
                        'month' => $month,
                        'amount_due' => number_format($amountDue, 2, '.', ''),
                        'amount_paid' => '0.00',
                        'status' => ClubMonthlyFee::STATUS_UNPAID,
                        'created_by' => $userId,
                    ]);

                    $createdCount++;
                }
            } else {
                $query = ClubSubscription::with(['club', 'enrollment'])
                    ->where('academic_year_id', $academicYearId)
                    ->where('status', 'active')
                    ->whereNull('excluded_at')
                    ->whereHas('club', fn ($q) => $q->where('is_active', true));

                if ($sectionId) {
                    $query->whereHas('enrollment', fn ($q) => $q->where('section_id', $sectionId));
                }

                $subscriptions = $query->get();

                foreach ($subscriptions as $sub) {
                    $amountDue = $sub->monthly_fee_override !== null
                        ? (float) $sub->monthly_fee_override
                        : (float) ($sub->club?->monthly_fee ?? 0);

                    $fee = ClubMonthlyFee::where('student_id', $sub->student_id)
                        ->where('club_id', $sub->club_id)
                        ->where('month', $month)
                        ->where('academic_year_id', $academicYearId)
                        ->first();

                    if ($fee) {
                        $skippedCount++;
                        continue;
                    }

                    ClubMonthlyFee::create([
                        'student_id' => $sub->student_id,
                        'club_id' => $sub->club_id,
                        'academic_year_id' => $academicYearId,
                        'enrollment_id' => $sub->enrollment_id,
                        'club_subscription_id' => $sub->id,
                        'month' => $month,
                        'amount_due' => number_format($amountDue, 2, '.', ''),
                        'amount_paid' => '0.00',
                        'status' => ClubMonthlyFee::STATUS_UNPAID,
                        'created_by' => $userId,
                    ]);

                    $createdCount++;
                }
            }

            return [
                'month' => $month,
                'created' => $createdCount,
                'skipped' => $skippedCount,
            ];
        });
    }

    /**
     * خلاص معلوم نادي شهري مع الإسقاط في الدفتر النقدي داخل معاملة واحدة.
     */
    public function recordPayment(
        ClubMonthlyFee $monthlyFee,
        float $amountPaid,
        string $paidAt,
        string $method,
        ?string $reference = null,
        ?string $notes = null,
        ?int $userId = null
    ): ClubMonthlyFee {
        if ($monthlyFee->cancelled_at !== null) {
            throw new InvalidArgumentException('لا يمكن خلاص سجل ملغى');
        }

        if ($amountPaid < 0) {
            throw new InvalidArgumentException('مبلغ الدفع يجب أن يكون رقماً موجباً');
        }

        $amountDue = (float) $monthlyFee->amount_due;

        if ($amountPaid > $amountDue) {
            throw new InvalidArgumentException('المبلغ المدفوع يتجاوز المطلوب (' . number_format($amountDue, 2, '.', '') . ')');
        }

        return DB::transaction(function () use ($monthlyFee, $amountPaid, $paidAt, $method, $reference, $notes, $userId, $amountDue) {
            $locked = ClubMonthlyFee::whereKey($monthlyFee->getKey())->lockForUpdate()->firstOrFail();

            $status = ClubMonthlyFee::STATUS_UNPAID;
            if ($amountPaid >= $amountDue) {
                $status = ClubMonthlyFee::STATUS_PAID;
            } elseif ($amountPaid > 0) {
                $status = ClubMonthlyFee::STATUS_PARTIAL;
            }

            $locked->update([
                'amount_paid' => number_format($amountPaid, 2, '.', ''),
                'status' => $status,
                'paid_at' => $paidAt,
                'method' => $method,
                'reference' => $reference,
                'notes' => $notes,
                'created_by' => $userId ?? $locked->created_by,
            ]);

            $fresh = $locked->fresh(['student', 'club', 'academicYear']);

            $this->ledger->recordClubFeePayment($fresh);

            return $fresh;
        });
    }

    /**
     * إلغاء استخلاص معلوم نادي.
     */
    public function cancelPayment(ClubMonthlyFee $monthlyFee, int $userId, string $reason): ClubMonthlyFee
    {
        if ($monthlyFee->cancelled_at !== null) {
            throw new InvalidArgumentException('هذا السجل ملغى مسبقاً');
        }

        return DB::transaction(function () use ($monthlyFee, $userId, $reason) {
            $monthlyFee->update([
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => $reason,
            ]);

            $this->ledger->cancelFor($monthlyFee, $userId, $reason);

            return $monthlyFee->fresh();
        });
    }

    /**
     * حذف سجل غير مدفوع فقط.
     */
    public function deleteFeeRecord(ClubMonthlyFee $monthlyFee): void
    {
        if ((float) $monthlyFee->amount_paid > 0 || $monthlyFee->status !== ClubMonthlyFee::STATUS_UNPAID) {
            throw new InvalidArgumentException('لا يمكن حذف سجل مدفوع أو خالِص؛ ألغِ العملية بدلاً من الحذف');
        }

        $monthlyFee->delete();
    }

    /**
     * تقرير كشف معلوم النوادي المدرسية.
     */
    public function getReport(array $filters): array
    {
        $month = $filters['month'] ?? now()->format('Y-m');
        $academicYearId = (int) ($filters['academic_year_id'] ?? AcademicYear::where('is_active', true)->value('id') ?? 1);

        $sectionId = ! empty($filters['section_id']) ? (int) $filters['section_id'] : null;
        $levelId = ! empty($filters['level_id']) ? (int) $filters['level_id'] : null;
        $clubId = ! empty($filters['club_id']) ? (int) $filters['club_id'] : null;
        $statusFilter = ! empty($filters['status']) && $filters['status'] !== 'all' ? $filters['status'] : null;
        $search = ! empty($filters['search']) ? '%' . trim($filters['search']) . '%' : null;

        if (($sectionId || $levelId) && $clubId) {
            $club = Club::with('levels')->find($clubId);
            if ($club && $club->is_active) {
                $allowedLevels = $club->levels->pluck('id')->all();

                $enrollmentsQuery = Enrollment::where('academic_year_id', $academicYearId)
                    ->where('status', 'active');

                if ($sectionId) {
                    $enrollmentsQuery->where('section_id', $sectionId);
                } elseif ($levelId) {
                    $enrollmentsQuery->where('level_id', $levelId);
                }

                if ($allowedLevels !== []) {
                    $enrollmentsQuery->whereIn('level_id', $allowedLevels);
                }

                if ($search) {
                    $enrollmentsQuery->whereHas('student', fn ($q) => $q->where('first_name', 'like', $search)
                        ->orWhere('last_name', 'like', $search)
                        ->orWhere('student_code', 'like', $search));
                }

                $activeEnrollments = $enrollmentsQuery->get();

                foreach ($activeEnrollments as $enr) {
                    $sub = ClubSubscription::where('student_id', $enr->student_id)
                        ->where('club_id', $clubId)
                        ->where('academic_year_id', $academicYearId)
                        ->first();

                    if ($sub && $sub->excluded_at !== null) {
                        continue;
                    }

                    if (! $sub) {
                        $sub = ClubSubscription::create([
                            'student_id' => $enr->student_id,
                            'club_id' => $clubId,
                            'academic_year_id' => $academicYearId,
                            'enrollment_id' => $enr->id,
                            'start_date' => now()->toDateString(),
                            'status' => 'active',
                        ]);
                    }

                    $amountDue = $sub->monthly_fee_override !== null
                        ? (float) $sub->monthly_fee_override
                        : (float) $club->monthly_fee;

                    ClubMonthlyFee::firstOrCreate(
                        [
                            'student_id' => $enr->student_id,
                            'club_id' => $clubId,
                            'month' => $month,
                            'academic_year_id' => $academicYearId,
                        ],
                        [
                            'enrollment_id' => $enr->id,
                            'club_subscription_id' => $sub->id,
                            'amount_due' => number_format($amountDue, 2, '.', ''),
                            'amount_paid' => '0.00',
                            'status' => ClubMonthlyFee::STATUS_UNPAID,
                        ]
                    );
                }
            }
        }

        $query = ClubMonthlyFee::with([
            'student:id,first_name,last_name,student_code',
            'student.enrollments' => fn ($q) => $q->where('academic_year_id', $academicYearId)->with(['level:id,name', 'section:id,name']),
            'subscription',
            'club:id,name,monthly_fee',
            'academicYear:id,name',
        ])
            ->whereNull('cancelled_at')
            ->where('month', $month)
            ->where('academic_year_id', $academicYearId);

        if ($clubId) {
            $query->where('club_id', $clubId);
        }

        if ($statusFilter) {
            if ($statusFilter === 'pending' || $statusFilter === 'unpaid') {
                $query->whereIn('status', ['unpaid', 'partial']);
            } elseif ($statusFilter === 'paid') {
                $query->where('status', 'paid');
            } elseif ($statusFilter === 'partial') {
                $query->where('status', 'partial');
            }
        }

        if ($search) {
            $query->whereHas('student', fn ($q) => $q->where('first_name', 'like', $search)
                ->orWhere('last_name', 'like', $search)
                ->orWhere('student_code', 'like', $search));
        }

        if ($sectionId) {
            $query->where(function ($q) use ($academicYearId, $sectionId) {
                $q->whereHas('enrollment', fn ($eq) => $eq->where('academic_year_id', $academicYearId)->where('section_id', $sectionId))
                  ->orWhereHas('student.enrollments', fn ($sq) => $sq->where('academic_year_id', $academicYearId)->where('section_id', $sectionId));
            });
        } elseif ($levelId) {
            $query->where(function ($q) use ($academicYearId, $levelId) {
                $q->whereHas('enrollment', fn ($eq) => $eq->where('academic_year_id', $academicYearId)->where('level_id', $levelId))
                  ->orWhereHas('student.enrollments', fn ($sq) => $sq->where('academic_year_id', $academicYearId)->where('level_id', $levelId));
            });
        }

        $records = $query->get();

        $enrolledCount = 0;
        $paidCount = 0;
        $pendingCount = 0;
        $totalDue = 0.0;
        $totalPaid = 0.0;

        $items = $records->filter(function ($row) {
            if ($row->subscription && $row->subscription->excluded_at !== null && (float) $row->amount_paid <= 0) {
                return false;
            }
            return true;
        })->map(function ($row) use (&$enrolledCount, &$paidCount, &$pendingCount, &$totalDue, &$totalPaid, $academicYearId) {
            $due = (float) $row->amount_due;
            $paid = (float) $row->amount_paid;
            $remaining = max(0, round($due - $paid, 2));

            $enrolledCount++;
            $totalDue += $due;
            $totalPaid += $paid;

            if ($row->status === ClubMonthlyFee::STATUS_PAID) {
                $paidCount++;
            } else {
                $pendingCount++;
            }

            $currentEnrollment = $row->student?->enrollments
                ?->firstWhere('academic_year_id', $academicYearId);

            $sub = $row->subscription;

            return [
                'id' => $row->id,
                'month' => $row->month,
                'student_id' => $row->student_id,
                'student_name' => trim(($row->student?->first_name ?? '') . ' ' . ($row->student?->last_name ?? '')),
                'student_code' => $row->student?->student_code,
                'level_name' => $currentEnrollment?->level?->name ?? '-',
                'section_name' => $currentEnrollment?->section?->name ?? '-',
                'club_id' => $row->club_id,
                'club_name' => $row->club?->name ?? '-',
                'amount_due' => $due,
                'amount_paid' => $paid,
                'remaining' => $remaining,
                'status' => $row->status,
                'status_label' => ClubMonthlyFee::STATUS_LABELS[$row->status] ?? $row->status,
                'status_color' => ClubMonthlyFee::STATUS_COLORS[$row->status] ?? 'orange',
                'paid_at' => $row->paid_at?->toDateString(),
                'method' => $row->method,
                'notes' => $row->notes,
                'subscription_id' => $sub?->id,
                'is_excluded' => $sub?->excluded_at !== null,
                'excluded_at' => $sub?->excluded_at?->toDateTimeString(),
            ];
        })
            ->sortBy([
                ['level_name', 'asc'],
                ['section_name', 'asc'],
                ['student_name', 'asc'],
            ])
            ->values();

        $totalRemaining = max(0, round($totalDue - $totalPaid, 2));

        return [
            'month' => $month,
            'academic_year_id' => $academicYearId,
            'summary' => [
                'enrolled_count' => $enrolledCount,
                'paid_count' => $paidCount,
                'pending_count' => $pendingCount,
                'unpaid_count' => $pendingCount,
                'total_due' => round($totalDue, 2),
                'total_paid' => round($totalPaid, 2),
                'total_remaining' => $totalRemaining,
            ],
            'records' => $items,
        ];
    }
}
