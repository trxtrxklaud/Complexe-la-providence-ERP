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

        $fees = ClubMonthlyFee::where('club_id', $club->id)
            ->where('academic_year_id', $activeYearId)
            ->where('month', $currentMonth)
            ->where('status', ClubMonthlyFee::STATUS_UNPAID)
            ->where(function ($q) {
                $q->whereNull('amount_paid')->orWhere('amount_paid', '<=', 0);
            })
            ->whereNull('cancelled_at')
            ->get();

        $count = 0;
        foreach ($fees as $fee) {
            $fee->update(['amount_due' => number_format((float) $club->monthly_fee, 2, '.', '')]);
            $studentFee = $fee->studentFee()->first();
            if ($studentFee && (float) $studentFee->direct_paid_amount <= 0 && (float) $fee->amount_paid <= 0) {
                $studentFee->update([
                    'amount_due' => number_format((float) $club->monthly_fee, 2, '.', ''),
                    'status' => 'pending',
                ]);
            }
            $count++;
        }

        return $count;
    }

    private function ensureStudentFeeForClubMonthlyFee(ClubMonthlyFee $monthlyFee): \App\Models\StudentFee
    {
        $clubName = $monthlyFee->club()->value('name') ?? 'النادي';

        return \App\Models\StudentFee::firstOrCreate(
            ['club_monthly_fee_id' => $monthlyFee->id],
            [
                'enrollment_id' => $monthlyFee->enrollment_id,
                'fee_plan_id' => null,
                'fee_type_id' => null,
                'description' => 'معلوم نادي '.$clubName.' — '.$monthlyFee->month,
                'amount_due' => $monthlyFee->amount_due,
                'direct_paid_amount' => $monthlyFee->amount_paid,
                'due_date' => $monthlyFee->month.'-01',
                'status' => ClubMonthlyFee::STATUS_UNPAID === $monthlyFee->status ? 'pending' : 'partial',
            ]
        );
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

    public function ensureFeesForEnrollment(Enrollment $enrollment, array $months, ?int $userId = null): void
    {
        $subscriptions = ClubSubscription::with('club')
            ->where('student_id', $enrollment->student_id)
            ->where('academic_year_id', $enrollment->academic_year_id)
            ->where('status', 'active')
            ->whereNull('excluded_at')
            ->whereHas('club', fn ($q) => $q->where('is_active', true))
            ->get();

        foreach ($subscriptions as $subscription) {
            foreach ($months as $month) {
                $amountDue = $subscription->monthly_fee_override !== null
                    ? (float) $subscription->monthly_fee_override
                    : (float) ($subscription->club?->monthly_fee ?? 0);

                $fee = ClubMonthlyFee::firstOrCreate(
                    [
                        'student_id' => $enrollment->student_id,
                        'club_id' => $subscription->club_id,
                        'month' => $month,
                        'academic_year_id' => $enrollment->academic_year_id,
                    ],
                    [
                        'enrollment_id' => $enrollment->id,
                        'club_subscription_id' => $subscription->id,
                        'amount_due' => number_format($amountDue, 2, '.', ''),
                        'amount_paid' => '0.00',
                        'status' => ClubMonthlyFee::STATUS_UNPAID,
                        'created_by' => $userId,
                    ]
                );

                $this->ensureStudentFeeForClubMonthlyFee($fee);
            }
        }
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

                    $fee = ClubMonthlyFee::create([
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

                    $this->ensureStudentFeeForClubMonthlyFee($fee);
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

                    $fee = ClubMonthlyFee::create([
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

                    $this->ensureStudentFeeForClubMonthlyFee($fee);
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

        // فحص التخفيضات الشهرية السارية على النادي
        $sub = $monthlyFee->subscription;
        if ($sub) {
            $activeDiscount = \App\Models\ClubMonthlyDiscount::query()
                ->where('club_subscription_id', $sub->id)
                ->active()
                ->where('start_month', '<=', $monthlyFee->month)
                ->where('end_month', '>=', $monthlyFee->month)
                ->first();



            if ($activeDiscount) {
                if ($activeDiscount->discount_type === \App\Models\ClubMonthlyDiscount::TYPE_FULL_WAIVER) {
                    throw new InvalidArgumentException('معلوم النادي لهذا الشهر معفى كلياً ولا يوجد مبلغ مستحق.');
                } elseif ($activeDiscount->discount_type === \App\Models\ClubMonthlyDiscount::TYPE_HUMANITARIAN_FIXED) {
                    $netDue = max(0.0, round($amountDue - (float) $activeDiscount->monthly_amount, 2));
                    if ($amountPaid > $netDue) {
                        throw new InvalidArgumentException('المبلغ المدفوع (' . number_format($amountPaid, 2, '.', '') . ') يتجاوز الصافي المستحق بعد التخفيض الإنساني (' . number_format($netDue, 2, '.', '') . ')');
                    }
                    $amountDue = $netDue;
                }
            }
        }

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

            $studentFee = $locked->studentFee()->first();
            if ($studentFee) {
                $allocated = $studentFee->allocatedAmount();
                $studentFee->update(['direct_paid_amount' => max(0, (float) $locked->amount_paid - $allocated)]);
            }
            $this->syncStudentFeeFromClubFee($locked);
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

            $studentFee = $monthlyFee->studentFee()->first();
            $allocated = $studentFee?->allocatedAmount() ?? 0.0;
            $monthlyFee->update([
                'amount_paid' => number_format($allocated, 2, '.', ''),
                'status' => $allocated > 0 ? ClubMonthlyFee::STATUS_PARTIAL : ClubMonthlyFee::STATUS_UNPAID,
            ]);
            $monthlyFee->studentFee()->update(['direct_paid_amount' => 0]);
            $this->syncStudentFeeFromClubFee($monthlyFee->fresh());
            $this->ledger->cancelFor($monthlyFee, $userId, $reason);

            return $monthlyFee->fresh();
        });
    }

    private function syncStudentFeeFromClubFee(ClubMonthlyFee $monthlyFee): void
    {
        $studentFee = $monthlyFee->studentFee()->first();
        if (! $studentFee) {
            $studentFee = $this->ensureStudentFeeForClubMonthlyFee($monthlyFee);
        }

        $allocated = $studentFee->allocatedAmount();
        $directPaid = max(0.0, (float) $monthlyFee->amount_paid - $allocated);
        $waived = $studentFee->waivedAmount();
        $status = match (true) {
            $allocated + $directPaid + $waived >= (float) $studentFee->amount_due => 'paid',
            $allocated + $directPaid > 0 => 'partial',
            default => 'pending',
        };

        $studentFee->update([
            'amount_due' => $monthlyFee->amount_due,
            'direct_paid_amount' => $directPaid,
            'status' => $status,
        ]);
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
     * Dashboard المتخلدات: يجمع فقط الأرصدة غير المسددة حسب القسم والتلميذ والنادي.
     */
    public function getArrearsDashboard(array $filters): array
    {
        $academicYearId = (int) ($filters['academic_year_id'] ?? AcademicYear::where('is_active', true)->value('id') ?? 1);
        $sectionId = ! empty($filters['section_id']) ? (int) $filters['section_id'] : null;
        $levelId = ! empty($filters['level_id']) ? (int) $filters['level_id'] : null;
        $clubId = ! empty($filters['club_id']) ? (int) $filters['club_id'] : null;
        $search = ! empty($filters['search']) ? '%' . trim($filters['search']) . '%' : null;

        $records = ClubMonthlyFee::query()
            ->with([
                'student:id,first_name,last_name,student_code,guardian_phone',
                'enrollment:id,student_id,section_id,level_id',
                'enrollment.section:id,name,level_id',
                'enrollment.level:id,name',
                'club:id,name,monthly_fee',
            ])
            ->where('academic_year_id', $academicYearId)
            ->whereNull('cancelled_at')
            ->whereColumn('amount_paid', '<', 'amount_due')
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->when($sectionId, fn ($q) => $q->whereHas('enrollment', fn ($eq) => $eq->where('section_id', $sectionId)))
            ->when($levelId, fn ($q) => $q->whereHas('enrollment', fn ($eq) => $eq->where('level_id', $levelId)))
            ->when($search, fn ($q) => $q->whereHas('student', fn ($sq) => $sq
                ->where('first_name', 'like', $search)
                ->orWhere('last_name', 'like', $search)
                ->orWhere('student_code', 'like', $search)))
            ->orderBy('month')
            ->get()
            ->map(function (ClubMonthlyFee $fee): array {
                $due = (float) $fee->amount_due;
                $paid = (float) $fee->amount_paid;
                $remaining = max(0.0, round($due - $paid, 2));
                $enrollment = $fee->enrollment;
                $section = $enrollment?->section;
                $level = $enrollment?->level;

                return [
                    'id' => $fee->id,
                    'month' => $fee->month,
                    'student_id' => $fee->student_id,
                    'student_name' => trim(($fee->student?->first_name ?? '') . ' ' . ($fee->student?->last_name ?? '')),
                    'student_code' => $fee->student?->student_code,
                    'guardian_phone' => $fee->student?->guardian_phone,
                    'level_id' => $level?->id,
                    'level_name' => $level?->name ?? '—',
                    'section_id' => $section?->id,
                    'section_name' => $section?->name ?? 'غير محدد',
                    'club_id' => $fee->club_id,
                    'club_name' => $fee->club?->name ?? '—',
                    'amount_due' => $due,
                    'amount_paid' => $paid,
                    'remaining' => $remaining,
                    'status' => $fee->status,
                ];
            })
            ->filter(fn (array $row) => $row['remaining'] > 0)
            ->values();

        $students = $records->groupBy('student_id')->map(function ($rows): array {
            $first = $rows->first();
            return [
                'student_id' => $first['student_id'],
                'student_name' => $first['student_name'],
                'student_code' => $first['student_code'],
                'guardian_phone' => $first['guardian_phone'],
                'level_name' => $first['level_name'],
                'section_id' => $first['section_id'],
                'section_name' => $first['section_name'],
                'clubs_count' => $rows->pluck('club_id')->unique()->count(),
                'months_count' => $rows->count(),
                'total_remaining' => round($rows->sum('remaining'), 2),
                'details' => $rows->values()->all(),
            ];
        })->sortBy([['section_name', 'asc'], ['student_name', 'asc']])->values();

        $sections = $students->groupBy(fn (array $row) => (string) ($row['section_id'] ?? 'none'))
            ->map(function ($rows): array {
                $first = $rows->first();
                return [
                    'section_id' => $first['section_id'],
                    'section_name' => $first['section_name'],
                    'students_count' => $rows->count(),
                    'clubs_count' => collect($rows)->flatMap(fn ($row) => collect($row['details'])->pluck('club_id'))->unique()->count(),
                    'fees_count' => collect($rows)->sum('months_count'),
                    'total_remaining' => round($rows->sum('total_remaining'), 2),
                    'students' => $rows->values()->all(),
                ];
            })->sortBy('section_name')->values();

        return [
            'academic_year_id' => $academicYearId,
            'summary' => [
                'sections_count' => $sections->count(),
                'students_count' => $students->count(),
                'clubs_count' => $records->pluck('club_id')->unique()->count(),
                'fees_count' => $records->count(),
                'total_due' => round($records->sum('amount_due'), 2),
                'total_paid' => round($records->sum('amount_paid'), 2),
                'total_remaining' => round($records->sum('remaining'), 2),
            ],
            'sections' => $sections->values()->all(),
            'students' => $students->values()->all(),
        ];
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

        // ملاحظة أمنية: التقرير قراءة صرفة. لا تُنشأ اشتراكات ولا سجلات أشهر هنا أبداً؛
        // توليد سجلات الشهر حصري عبر POST /reports/club-fees/generate (generateMonthFees).

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
        })->map(function ($row) use (&$enrolledCount, &$paidCount, &$pendingCount, &$totalDue, &$totalPaid, $academicYearId, $month) {
            $due = (float) $row->amount_due;
            $paid = (float) $row->amount_paid;

            $sub = $row->subscription;
            $activeDiscount = null;
            if ($sub) {
                $activeDiscount = \App\Models\ClubMonthlyDiscount::query()
                    ->where('club_subscription_id', $sub->id)
                    ->active()
                    ->where('start_month', '<=', $month)
                    ->where('end_month', '>=', $month)
                    ->first();
            }



            $discountAmount = 0.0;
            $isFullWaiver = false;

            if ($activeDiscount) {
                if ($activeDiscount->discount_type === \App\Models\ClubMonthlyDiscount::TYPE_FULL_WAIVER) {
                    $discountAmount = $due;
                    $isFullWaiver = true;
                } elseif ($activeDiscount->discount_type === \App\Models\ClubMonthlyDiscount::TYPE_HUMANITARIAN_FIXED) {
                    $discountAmount = (float) $activeDiscount->monthly_amount;
                }

            }

            $netDue = max(0.0, round($due - $discountAmount, 2));
            $remaining = max(0.0, round($netDue - $paid, 2));

            $enrolledCount++;
            $totalDue += $netDue;
            $totalPaid += $paid;

            if ($isFullWaiver || $remaining <= 0) {
                $paidCount++;
                $statusKey = $isFullWaiver ? 'paid' : $row->status;
                $statusLabel = $isFullWaiver ? 'تخفيض كلي' : (ClubMonthlyFee::STATUS_LABELS[$row->status] ?? $row->status);
                $statusColor = $isFullWaiver ? 'green' : (ClubMonthlyFee::STATUS_COLORS[$row->status] ?? 'orange');
            } else {
                $pendingCount++;
                $statusKey = $row->status;
                $statusLabel = ClubMonthlyFee::STATUS_LABELS[$row->status] ?? $row->status;
                $statusColor = ClubMonthlyFee::STATUS_COLORS[$row->status] ?? 'orange';
            }

            $currentEnrollment = $row->student?->enrollments
                ?->firstWhere('academic_year_id', $academicYearId);

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
                'discount_amount' => $discountAmount,
                'net_due' => $netDue,
                'amount_paid' => $paid,
                'remaining' => $remaining,
                'status' => $statusKey,
                'status_label' => $statusLabel,
                'status_color' => $statusColor,
                'paid_at' => $row->paid_at?->toDateString(),
                'method' => $row->method,
                'notes' => $row->notes,
                'subscription_id' => $sub?->id,
                'is_excluded' => $sub?->excluded_at !== null,
                'excluded_at' => $sub?->excluded_at?->toDateTimeString(),
                'discount_type' => $activeDiscount?->discount_type,
                'discount_reason' => $activeDiscount?->reason,
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
