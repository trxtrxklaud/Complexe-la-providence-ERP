<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Club;
use App\Models\ClubMonthlyDiscount;
use App\Models\ClubMonthlyFee;
use App\Models\ClubSubscription;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\FeeType;
use App\Models\Student;
use App\Models\StudentFee;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ClubService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * إنشاء نادي وتخصيص المستويات والأقسام المسموح لها بدراسته.
     */
    public function createClub(array $data, array $levelIds = [], array $sectionIds = []): Club
    {
        return DB::transaction(function () use ($data, $levelIds, $sectionIds) {
            $feeCategoryId = $data['fee_category_id'] ?? null;
            if (! $feeCategoryId) {
                $feeCategory = FeeCategory::firstOrCreate(
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

            if ($sectionIds !== []) {
                $club->sections()->sync($sectionIds);
            }

            $this->syncFeeTypePrice($club);

            return $club->fresh(['levels', 'sections']);
        });
    }

    /**
     * تعديل بيانات النادي والمستويات والأقسام المسموح لها.
     */
    public function updateClub(Club $club, array $data, ?array $levelIds = null, ?array $sectionIds = null): Club
    {
        return DB::transaction(function () use ($club, $data, $levelIds, $sectionIds) {
            $club->update($data);

            if ($levelIds !== null) {
                $club->levels()->sync($levelIds);
            }

            if ($sectionIds !== null) {
                $club->sections()->sync($sectionIds);
                $this->cleanUnpaidFeesForRemovedSections($club, $sectionIds);
            }

            $this->syncFeeTypePrice($club);
            $count = $this->syncUnpaidFeesForClub($club);

            $fresh = $club->fresh(['levels', 'sections']);
            $fresh->setAttribute('updated_unpaid_count', $count);

            return $fresh;
        });
    }

    /**
     * تنظيف السجلات غير المدفوعة للأقسام التي تم إلغاء ربطها بالنادي من إدارة النوادي.
     */
    private function cleanUnpaidFeesForRemovedSections(Club $club, array $activeSectionIds): void
    {
        if ($activeSectionIds === []) {
            return;
        }

        $feesToDelete = ClubMonthlyFee::query()
            ->where('club_id', $club->id)
            ->where('status', ClubMonthlyFee::STATUS_UNPAID)
            ->where(function ($q) {
                $q->whereNull('amount_paid')->orWhere('amount_paid', '<=', 0);
            })
            ->whereHas('enrollment', function ($eq) use ($activeSectionIds) {
                $eq->whereNotIn('section_id', $activeSectionIds);
            })
            ->get();

        foreach ($feesToDelete as $fee) {
            $fee->studentFee()?->delete();
            $fee->delete();
        }
    }

    /**
     * مزامنة مبلغ معلوم النادي مع قائمة أنواع المعاليم العامة.
     */
    private function syncFeeTypePrice(Club $club): void
    {
        $normalizedClubName = FeeType::normalize($club->name);

        FeeType::all()->each(function ($feeType) use ($normalizedClubName, $club) {
            $normalizedFeeTypeName = FeeType::normalize($feeType->name_ar);
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
     * مزامنة مبلغ معلوم النادي في كافة السجلات الشهرية غير المدفوعة للسنة الدراسية النشطة.
     * تحمي السجلات المدفوعة بالكامل أو جزئياً، والاشتراكات ذات الأسعار المخصصة (override)،
     * والسنوات السابقة أو المقفلة، والسجلات ذات الحركات المالية النشطة في الخزينة.
     */
    public function syncUnpaidFeesForClub(Club $club): int
    {
        $activeYearId = AcademicYear::where('is_active', true)->whereNull('closed_at')->value('id');
        if (! $activeYearId) {
            return 0;
        }

        $fees = ClubMonthlyFee::where('club_id', $club->id)
            ->where('academic_year_id', $activeYearId)
            ->where('status', ClubMonthlyFee::STATUS_UNPAID)
            ->where(function ($q) {
                $q->whereNull('amount_paid')->orWhere('amount_paid', '<=', 0);
            })
            ->whereNull('cancelled_at')
            ->whereDoesntHave('subscription', function ($subQ) {
                $subQ->whereNotNull('monthly_fee_override');
            })
            ->whereDoesntHave('studentFee.paymentAllocations', function ($allocQ) {
                $allocQ->whereHas('payment', fn ($p) => $p->whereNull('cancelled_at'));
            })
            ->get();

        $newPrice = number_format((float) $club->monthly_fee, 2, '.', '');
        $count = 0;

        foreach ($fees as $fee) {
            $fee->update(['amount_due' => $newPrice]);
            $studentFee = $fee->studentFee()->first();
            if ($studentFee && (float) $studentFee->direct_paid_amount <= 0) {
                $studentFee->update([
                    'amount_due' => $newPrice,
                    'status' => 'pending',
                ]);
            }
            $count++;
        }

        return $count;
    }

    private function ensureStudentFeeForClubMonthlyFee(ClubMonthlyFee $monthlyFee): StudentFee
    {
        $clubName = $monthlyFee->club()->value('name') ?? 'النادي';

        return StudentFee::firstOrCreate(
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

            $yearModel = AcademicYear::find($academicYearId);
            $defaultStart = $yearModel && $yearModel->start_date
                ? $yearModel->start_date->toDateString()
                : now()->toDateString();

            $subscription = ClubSubscription::updateOrCreate(
                [
                    'student_id' => $studentId,
                    'club_id' => $clubId,
                    'academic_year_id' => $academicYearId,
                ],
                [
                    'enrollment_id' => $enrollmentId,
                    'start_date' => $startDate ?? $defaultStart,
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
        $academicYear = $enrollment->academicYear;
        if (! $academicYear) {
            return;
        }

        $validMonths = $this->getAcademicYearMonths($enrollment->academic_year_id, null, null, false);

        // Find active clubs assigned to this enrollment's section (or level/subscriptions)
        $eligibleClubs = Club::query()
            ->where('is_active', true)
            ->where(function ($cq) use ($enrollment) {
                $cq->whereHas('sections', fn ($sq) => $sq->where('sections.id', $enrollment->section_id))
                    ->orWhere(function ($orQ) use ($enrollment) {
                        $orQ->whereDoesntHave('sections')
                            ->whereHas('levels', fn ($lq) => $lq->where('levels.id', $enrollment->level_id));
                    })
                    ->orWhereHas('subscriptions', fn ($subQ) => $subQ->where('student_id', $enrollment->student_id)
                        ->where('academic_year_id', $enrollment->academic_year_id)
                        ->where('status', 'active')
                        ->whereNull('excluded_at'));
            })
            ->get();

        foreach ($eligibleClubs as $club) {
            $subscription = ClubSubscription::where('student_id', $enrollment->student_id)
                ->where('club_id', $club->id)
                ->where('academic_year_id', $enrollment->academic_year_id)
                ->first();

            if ($subscription && ($subscription->excluded_at !== null || $subscription->status === 'cancelled')) {
                continue;
            }

            if (! $subscription) {
                $subscription = ClubSubscription::create([
                    'student_id' => $enrollment->student_id,
                    'club_id' => $club->id,
                    'academic_year_id' => $enrollment->academic_year_id,
                    'enrollment_id' => $enrollment->id,
                    'start_date' => $academicYear->start_date ? $academicYear->start_date->toDateString() : now()->toDateString(),
                    'status' => 'active',
                ]);
            }

            foreach ($months as $month) {
                if (! in_array($month, $validMonths, true)) {
                    continue;
                }

                // If subscription has a start_date later than the month, skip
                if ($subscription->start_date) {
                    $subStartMonth = substr($subscription->start_date->toDateString(), 0, 7);
                    if ($month < $subStartMonth) {
                        continue;
                    }
                }

                $amountDue = $subscription->monthly_fee_override !== null
                    ? (float) $subscription->monthly_fee_override
                    : (float) ($club->monthly_fee ?? 0);

                $fee = ClubMonthlyFee::firstOrCreate(
                    [
                        'student_id' => $enrollment->student_id,
                        'club_id' => $club->id,
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

            $academicYear = AcademicYear::findOrFail($academicYearId);
            $validMonths = $this->getAcademicYearMonths($academicYearId, null, null, false);
            if (! in_array($month, $validMonths, true)) {
                throw new InvalidArgumentException("الشهر {$month} لا ينتمي إلى السنة الدراسية {$academicYear->name}");
            }

            $clubsQuery = Club::query()->where('is_active', true);
            if ($clubId) {
                $clubsQuery->where('id', $clubId);
            }
            $clubs = $clubsQuery->get();

            foreach ($clubs as $club) {
                $enrollmentsQuery = Enrollment::where('academic_year_id', $academicYearId)
                    ->where('status', 'active')
                    ->where(function ($secQ) use ($club) {
                        $secQ->whereNotExists(function ($sq) use ($club) {
                            $sq->select(DB::raw(1))
                                ->from('club_sections')
                                ->where('club_id', $club->id);
                        })->orWhereExists(function ($sq) use ($club) {
                            $sq->select(DB::raw(1))
                                ->from('club_sections')
                                ->where('club_id', $club->id)
                                ->whereColumn('club_sections.section_id', 'enrollments.section_id');
                        });
                    })
                    ->where(function ($levQ) use ($club) {
                        $levQ->whereNotExists(function ($lq) use ($club) {
                            $lq->select(DB::raw(1))
                                ->from('club_levels')
                                ->where('club_id', $club->id);
                        })->orWhereExists(function ($lq) use ($club) {
                            $lq->select(DB::raw(1))
                                ->from('club_levels')
                                ->where('club_id', $club->id)
                                ->whereColumn('club_levels.level_id', 'enrollments.level_id');
                        });
                    });

                if ($sectionId) {
                    $enrollmentsQuery->where('section_id', $sectionId);
                }

                $enrollments = $enrollmentsQuery->get();

                foreach ($enrollments as $enr) {
                    $sub = ClubSubscription::where('student_id', $enr->student_id)
                        ->where('club_id', $club->id)
                        ->where('academic_year_id', $academicYearId)
                        ->first();

                    // If student was excluded or cancelled, skip
                    if ($sub && ($sub->excluded_at !== null || $sub->status === 'cancelled')) {
                        continue;
                    }

                    // If student started subscription after this month, skip
                    if ($sub && $sub->start_date !== null) {
                        $startMonth = substr($sub->start_date->toDateString(), 0, 7);
                        if ($month < $startMonth) {
                            continue;
                        }
                    }

                    if (! $sub) {
                        $sub = ClubSubscription::create([
                            'student_id' => $enr->student_id,
                            'club_id' => $club->id,
                            'academic_year_id' => $academicYearId,
                            'enrollment_id' => $enr->id,
                            'start_date' => $academicYear->start_date ? $academicYear->start_date->toDateString() : $month . '-01',
                            'status' => 'active',
                        ]);
                    }

                    $amountDue = $sub->monthly_fee_override !== null
                        ? (float) $sub->monthly_fee_override
                        : (float) $club->monthly_fee;

                    $fee = ClubMonthlyFee::where('student_id', $enr->student_id)
                        ->where('club_id', $club->id)
                        ->where('month', $month)
                        ->where('academic_year_id', $academicYearId)
                        ->first();

                    if ($fee) {
                        $skippedCount++;
                        continue;
                    }

                    $fee = ClubMonthlyFee::create([
                        'student_id' => $enr->student_id,
                        'club_id' => $club->id,
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

        if ($amountPaid <= 0) {
            throw new InvalidArgumentException('مبلغ الدفع يجب أن يكون رقماً موجباً');
        }

        $amountDue = (float) $monthlyFee->amount_due;

        // فحص التخفيضات الشهرية السارية على النادي
        $sub = $monthlyFee->subscription;
        if ($sub) {
            $activeDiscount = ClubMonthlyDiscount::query()
                ->where('club_subscription_id', $sub->id)
                ->active()
                ->where('start_month', '<=', $monthlyFee->month)
                ->where('end_month', '>=', $monthlyFee->month)
                ->first();

            if ($activeDiscount) {
                if ($activeDiscount->discount_type === ClubMonthlyDiscount::TYPE_FULL_WAIVER) {
                    throw new InvalidArgumentException('معلوم النادي لهذا الشهر معفى كلياً ولا يوجد مبلغ مستحق.');
                } elseif ($activeDiscount->discount_type === ClubMonthlyDiscount::TYPE_HUMANITARIAN_FIXED) {
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

        $fromMonth = ! empty($filters['from_month']) ? $filters['from_month'] : null;
        $toMonth = ! empty($filters['to_month']) ? $filters['to_month'] : null;

        $months = $this->getAcademicYearMonths($academicYearId, $fromMonth, $toMonth, $toMonth === null);

        $query = ClubMonthlyFee::query()
            ->with([
                'student:id,first_name,last_name,student_code,guardian_phone',
                'enrollment:id,student_id,section_id,level_id,academic_year_id',
                'enrollment.section:id,name,level_id',
                'enrollment.level:id,name',
                'club:id,name,monthly_fee,is_active',
                'studentFee.paymentAllocations.payment',
                'subscription.monthlyDiscounts',
            ])
            ->where('academic_year_id', $academicYearId)
            ->whereNull('cancelled_at')
            ->whereIn('month', $months)
            ->whereHas('club', fn ($cq) => $cq->where('is_active', true))
            ->whereExists(function ($sub) use ($academicYearId) {
                $sub->select(DB::raw(1))
                    ->from('enrollments')
                    ->whereColumn('enrollments.id', 'club_monthly_fees.enrollment_id')
                    ->whereColumn('enrollments.student_id', 'club_monthly_fees.student_id')
                    ->where('enrollments.academic_year_id', $academicYearId)
                    ->where(function ($secQ) {
                        $secQ->whereNotExists(function ($sq) {
                            $sq->select(DB::raw(1))
                                ->from('club_sections')
                                ->whereColumn('club_sections.club_id', 'club_monthly_fees.club_id');
                        })->orWhereExists(function ($sq) {
                            $sq->select(DB::raw(1))
                                ->from('club_sections')
                                ->whereColumn('club_sections.club_id', 'club_monthly_fees.club_id')
                                ->whereColumn('club_sections.section_id', 'enrollments.section_id');
                        });
                    })
                    ->where(function ($levSub) {
                        $levSub->whereNotExists(function ($lq) {
                            $lq->select(DB::raw(1))
                                ->from('club_levels')
                                ->whereColumn('club_levels.club_id', 'club_monthly_fees.club_id');
                        })->orWhereExists(function ($lq) {
                            $lq->select(DB::raw(1))
                                ->from('club_levels')
                                ->whereColumn('club_levels.club_id', 'club_monthly_fees.club_id')
                                ->whereColumn('club_levels.level_id', 'enrollments.level_id');
                        });
                    });
            });

        $records = $query
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->when($sectionId, fn ($q) => $q->whereHas('enrollment', fn ($eq) => $eq->where('section_id', $sectionId)))
            ->when($levelId, fn ($q) => $q->whereHas('enrollment', fn ($eq) => $eq->where('level_id', $levelId)))
            ->when($search, fn ($q) => $q->whereHas('student', fn ($sq) => $sq
                ->where('first_name', 'like', $search)
                ->orWhere('last_name', 'like', $search)
                ->orWhere('student_code', 'like', $search)))
            ->orderBy('month')
            ->get()
            ->filter(function (ClubMonthlyFee $fee) {
                $sub = $fee->subscription;
                if ($sub && $sub->start_date) {
                    $subStartMonth = substr($sub->start_date->toDateString(), 0, 7);
                    if ($fee->month < $subStartMonth && (float) $fee->amount_paid <= 0) {
                        return false;
                    }
                }
                if ($sub && $sub->excluded_at) {
                    $subExcludedMonth = substr($sub->excluded_at->toDateString(), 0, 7);
                    if ($fee->month >= $subExcludedMonth && (float) $fee->amount_paid <= 0) {
                        return false;
                    }
                }
                return true;
            })
            ->map(function (ClubMonthlyFee $fee): array {
                $due = (float) $fee->amount_due;

                $allocatedPaid = round(
                    (float) (
                        $fee->studentFee?->paymentAllocations
                            ?->filter(fn ($allocation) =>
                                $allocation->payment &&
                                $allocation->payment->cancelled_at === null
                            )
                            ->sum('amount_allocated') ?? 0
                    ),
                    2
                );
                $paid = $allocatedPaid > 0 ? $allocatedPaid : round((float) ($fee->amount_paid ?? 0), 2);

                $sub = $fee->subscription;
                $discountAmount = 0.0;
                $isFullWaiver = false;
                if ($sub) {
                    $activeDiscount = ClubMonthlyDiscount::query()
                        ->where('club_subscription_id', $sub->id)
                        ->active()
                        ->where('start_month', '<=', $fee->month)
                        ->where('end_month', '>=', $fee->month)
                        ->first();
                    if ($activeDiscount) {
                        if ($activeDiscount->discount_type === ClubMonthlyDiscount::TYPE_FULL_WAIVER) {
                            $discountAmount = $due;
                            $isFullWaiver = true;
                        } elseif ($activeDiscount->discount_type === ClubMonthlyDiscount::TYPE_HUMANITARIAN_FIXED) {
                            $discountAmount = (float) $activeDiscount->monthly_amount;
                        }
                    }
                }

                $netDue = max(0.0, round($due - $discountAmount, 2));
                $remaining = max(0.0, round($netDue - $paid, 2));
                $status = match (true) {
                    $isFullWaiver || $remaining <= 0 => 'paid',
                    $paid > 0 => 'partial',
                    default => 'unpaid',
                };

                $enrollment = $fee->enrollment;
                $section = $enrollment?->section;
                $level = $enrollment?->level;

                return [
                    'id' => $fee->id,
                    'fee_id' => $fee->id,
                    'month' => $fee->month,
                    'academic_year_id' => $fee->academic_year_id,
                    'student_id' => $fee->student_id,
                    'enrollment_id' => $fee->enrollment_id,
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
                    'discount_amount' => $discountAmount,
                    'net_due' => $netDue,
                    'amount_paid' => $paid,
                    'remaining' => $remaining,
                    'status' => $status,
                ];
            })
            ->filter(fn (array $row) => $row['remaining'] > 0)
            ->values();

        $students = $records->groupBy('student_id')->map(function ($rows): array {
            $first = $rows->first();
            $totalDue = round($rows->sum('net_due'), 2);
            $totalPaid = round($rows->sum('amount_paid'), 2);
            $totalRemaining = round($rows->sum('remaining'), 2);
            $status = $totalRemaining <= 0 ? 'paid' : ($totalPaid > 0 ? 'partial' : 'unpaid');

            return [
                'student_id' => $first['student_id'],
                'enrollment_id' => $first['enrollment_id'],
                'student_name' => $first['student_name'],
                'student_code' => $first['student_code'],
                'guardian_phone' => $first['guardian_phone'],
                'level_id' => $first['level_id'],
                'level_name' => $first['level_name'],
                'section_id' => $first['section_id'],
                'section_name' => $first['section_name'],
                'clubs_count' => $rows->pluck('club_id')->unique()->count(),
                'months_count' => $rows->count(),
                'fees_count' => $rows->count(),
                'clubs' => $rows->values()->all(),
                'total_due' => $totalDue,
                'total_paid' => $totalPaid,
                'total_remaining' => $totalRemaining,
                'status' => $status,
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
                    'fees_count' => collect($rows)->sum('fees_count'),
                    'months_count' => collect($rows)->sum('months_count'),
                    'total_due' => round(collect($rows)->sum('total_due'), 2),
                    'total_paid' => round(collect($rows)->sum('total_paid'), 2),
                    'total_remaining' => round(collect($rows)->sum('total_remaining'), 2),
                    'students' => $rows->values()->all(),
                ];
            })->sortBy('section_name')->values();

        return [
            'academic_year_id' => $academicYearId,
            'from_month' => $fromMonth,
            'to_month' => $toMonth,
            'summary' => [
                'sections_count' => $sections->count(),
                'students_count' => $students->count(),
                'clubs_count' => $records->pluck('club_id')->unique()->count(),
                'fees_count' => $records->count(),
                'months_count' => count($months),
                'total_due' => round($records->sum('net_due'), 2),
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

        $fromMonth = ! empty($filters['from_month']) ? $filters['from_month'] : null;
        $toMonth = ! empty($filters['to_month']) ? $filters['to_month'] : null;

        if ($fromMonth && $toMonth) {
            $months = $this->getAcademicYearMonths($academicYearId, $fromMonth, $toMonth, false);
        } elseif (is_array($month)) {
            $months = $month;
        } else {
            $months = [$month];
        }

        $query = ClubMonthlyFee::with([
            'student:id,first_name,last_name,student_code,guardian_phone',
            'enrollment:id,student_id,section_id,level_id,academic_year_id',
            'enrollment.section:id,name,level_id',
            'enrollment.level:id,name',
            'subscription.monthlyDiscounts',
            'club:id,name,monthly_fee,is_active',
            'academicYear:id,name',
            'studentFee.paymentAllocations.payment',
        ])
            ->whereNull('cancelled_at')
            ->where('academic_year_id', $academicYearId)
            ->whereIn('month', $months)
            ->whereHas('club', fn ($cq) => $cq->where('is_active', true))
            ->whereExists(function ($sub) use ($academicYearId) {
                $sub->select(DB::raw(1))
                    ->from('enrollments')
                    ->whereColumn('enrollments.id', 'club_monthly_fees.enrollment_id')
                    ->whereColumn('enrollments.student_id', 'club_monthly_fees.student_id')
                    ->where('enrollments.academic_year_id', $academicYearId)
                    ->where(function ($secQ) {
                        $secQ->whereNotExists(function ($sq) {
                            $sq->select(DB::raw(1))
                                ->from('club_sections')
                                ->whereColumn('club_sections.club_id', 'club_monthly_fees.club_id');
                        })->orWhereExists(function ($sq) {
                            $sq->select(DB::raw(1))
                                ->from('club_sections')
                                ->whereColumn('club_sections.club_id', 'club_monthly_fees.club_id')
                                ->whereColumn('club_sections.section_id', 'enrollments.section_id');
                        });
                    })
                    ->where(function ($levSub) {
                        $levSub->whereNotExists(function ($lq) {
                            $lq->select(DB::raw(1))
                                ->from('club_levels')
                                ->whereColumn('club_levels.club_id', 'club_monthly_fees.club_id');
                        })->orWhereExists(function ($lq) {
                            $lq->select(DB::raw(1))
                                ->from('club_levels')
                                ->whereColumn('club_levels.club_id', 'club_monthly_fees.club_id')
                                ->whereColumn('club_levels.level_id', 'enrollments.level_id');
                        });
                    });
            });

        if ($clubId) {
            $query->where('club_id', $clubId);
        }

        if ($search) {
            $query->whereHas('student', fn ($q) => $q->where('first_name', 'like', $search)
                ->orWhere('last_name', 'like', $search)
                ->orWhere('student_code', 'like', $search));
        }

        if ($sectionId) {
            $query->whereHas('enrollment', fn ($eq) => $eq->where('section_id', $sectionId));
        } elseif ($levelId) {
            $query->whereHas('enrollment', fn ($eq) => $eq->where('level_id', $levelId));
        }

        $records = $query->get();

        $enrolledCount = 0;
        $paidCount = 0;
        $pendingCount = 0;
        $totalDue = 0.0;
        $totalPaid = 0.0;

        $items = $records->filter(function ($row) {
            $sub = $row->subscription;
            if ($sub && $sub->start_date) {
                $subStartMonth = substr($sub->start_date->toDateString(), 0, 7);
                if ($row->month < $subStartMonth && (float) $row->amount_paid <= 0) {
                    return false;
                }
            }
            if ($sub && $sub->excluded_at !== null && (float) $row->amount_paid <= 0) {
                $excludedMonth = substr($sub->excluded_at->toDateString(), 0, 7);
                if ($row->month >= $excludedMonth) {
                    return false;
                }
            }
            return true;
        })->map(function ($row) use (&$enrolledCount, &$paidCount, &$pendingCount, &$totalDue, &$totalPaid, $academicYearId) {
            $due = (float) $row->amount_due;

            $allocatedPaid = round(
                (float) (
                    $row->studentFee?->paymentAllocations
                        ?->filter(fn ($allocation) =>
                            $allocation->payment &&
                            $allocation->payment->cancelled_at === null
                        )
                        ->sum('amount_allocated') ?? 0
                ),
                2
            );
            $paid = $allocatedPaid > 0 ? $allocatedPaid : round((float) ($row->amount_paid ?? 0), 2);

            $sub = $row->subscription;
            $discountAmount = 0.0;
            $isFullWaiver = false;
            if ($sub) {
                $activeDiscount = ClubMonthlyDiscount::query()
                    ->where('club_subscription_id', $sub->id)
                    ->active()
                    ->where('start_month', '<=', $row->month)
                    ->where('end_month', '>=', $row->month)
                    ->first();

                if ($activeDiscount) {
                    if ($activeDiscount->discount_type === ClubMonthlyDiscount::TYPE_FULL_WAIVER) {
                        $discountAmount = $due;
                        $isFullWaiver = true;
                    } elseif ($activeDiscount->discount_type === ClubMonthlyDiscount::TYPE_HUMANITARIAN_FIXED) {
                        $discountAmount = (float) $activeDiscount->monthly_amount;
                    }
                }
            }

            $netDue = max(0.0, round($due - $discountAmount, 2));
            $remaining = max(0.0, round($netDue - $paid, 2));

            $enrolledCount++;
            $totalDue += $netDue;
            $totalPaid += $paid;

            if ($isFullWaiver || $remaining <= 0) {
                $paidCount++;
                $statusKey = 'paid';
                $statusLabel = $isFullWaiver ? 'تخفيض كلي' : 'خلاص كامل';
                $statusColor = 'green';
            } elseif ($paid > 0) {
                $pendingCount++;
                $statusKey = 'partial';
                $statusLabel = 'في انتظار الدفع';
                $statusColor = 'orange';
            } else {
                $pendingCount++;
                $statusKey = 'unpaid';
                $statusLabel = 'في انتظار الدفع';
                $statusColor = 'orange';
            }

            $enrollment = $row->enrollment;
            $section = $enrollment?->section;
            $level = $enrollment?->level;

            return [
                'id' => $row->id,
                'academic_year_id' => $row->academic_year_id,
                'month' => $row->month,
                'enrollment_id' => $row->enrollment_id,
                'student_id' => $row->student_id,
                'student_name' => trim(($row->student?->first_name ?? '') . ' ' . ($row->student?->last_name ?? '')),
                'student_code' => $row->student?->student_code,
                'guardian_phone' => $row->student?->guardian_phone,
                'level_id' => $level?->id,
                'level_name' => $level?->name ?? '—',
                'section_id' => $section?->id,
                'section_name' => $section?->name ?? '—',
                'club_id' => $row->club_id,
                'club_name' => $row->club?->name ?? '—',
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
        });

        if ($statusFilter) {
            if ($statusFilter === 'pending' || $statusFilter === 'unpaid') {
                $items = $items->whereIn('status', ['unpaid', 'partial']);
            } elseif ($statusFilter === 'paid') {
                $items = $items->where('status', 'paid');
            } elseif ($statusFilter === 'partial') {
                $items = $items->where('status', 'partial');
            }
        }

        $sortedItems = $items
            ->sortBy([
                ['level_name', 'asc'],
                ['section_name', 'asc'],
                ['student_name', 'asc'],
            ])
            ->values();

        $totalRemaining = max(0.0, round($totalDue - $totalPaid, 2));

        return [
            'month' => is_array($month) ? implode(',', $month) : $month,
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
            'records' => $sortedItems->all(),
        ];
    }

    public function getAcademicYearMonths(int $academicYearId, ?string $fromMonth = null, ?string $toMonth = null, bool $onlyDue = false): array
    {
        $year = AcademicYear::find($academicYearId);
        $startYear = $year && $year->start_date ? (int) $year->start_date->format('Y') : (int) now()->format('Y');

        $schoolMonths = [
            sprintf('%04d-09', $startYear),
            sprintf('%04d-10', $startYear),
            sprintf('%04d-11', $startYear),
            sprintf('%04d-12', $startYear),
            sprintf('%04d-01', $startYear + 1),
            sprintf('%04d-02', $startYear + 1),
            sprintf('%04d-03', $startYear + 1),
            sprintf('%04d-04', $startYear + 1),
            sprintf('%04d-05', $startYear + 1),
        ];

        $currentMonth = now()->format('Y-m');

        $filtered = array_filter($schoolMonths, function ($m) use ($fromMonth, $toMonth, $onlyDue, $currentMonth, $year) {
            if ($fromMonth !== null && $m < $fromMonth) {
                return false;
            }
            if ($toMonth !== null && $m > $toMonth) {
                return false;
            }
            if ($onlyDue && ($year?->is_active ?? true)) {
                if ($toMonth === null && $m > $currentMonth) {
                    return false;
                }
            }
            return true;
        });

        return array_values($filtered);
    }
}
