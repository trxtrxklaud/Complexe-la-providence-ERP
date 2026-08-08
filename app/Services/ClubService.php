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
use RuntimeException;

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

            return $club->fresh('levels');
        });
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

            // التحقق من أن مستوى التلميذ مسموح له بدراسة النادي (إن كانت هناك مستويات محددة للنادي)
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
                ]
            );

            return $subscription->fresh(['student', 'club', 'academicYear']);
        });
    }

    /**
     * توليد سجلات الشهر لتلاميذ النوادي المسجلين فقط.
     *
     * يتم حفظ المبلغ المطلوب كـ snapshot، فلا تتأثر الأشهر القديمة بتغيير سعر النادي لاحقاً.
     * القيد الفريد يمنع تكرار السجلات عند الطلبات المتزامنة.
     */
    public function generateMonthFees(int $academicYearId, string $month, ?int $clubId = null, ?int $userId = null): array
    {
        return DB::transaction(function () use ($academicYearId, $month, $clubId, $userId) {
            $query = ClubSubscription::with(['club', 'enrollment'])
                ->where('academic_year_id', $academicYearId)
                ->where('status', 'active')
                ->whereHas('club', fn ($q) => $q->where('is_active', true));

            if ($clubId) {
                $query->where('club_id', $clubId);
            }

            $subscriptions = $query->get();

            $createdCount = 0;
            $skippedCount = 0;

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

            // إسقاط الأثر النقدي في الدفتر النقدي (idempotent عبر updateOrCreate)
            $this->ledger->recordClubFeePayment($fresh);

            return $fresh;
        } );
    }

    /**
     * إلغاء استخلاص أو سجل نادي.
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
     * حذف سجل معلوم نادي غير مدفوع فقط.
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

        $query = ClubMonthlyFee::with([
            'student:id,first_name,last_name,student_code',
            'student.enrollments' => fn ($q) => $q->where('academic_year_id', $academicYearId)->with(['level:id,name', 'section:id,name']),
            'club:id,name,monthly_fee',
            'academicYear:id,name',
        ])
            ->whereNull('cancelled_at')
            ->where('month', $month)
            ->where('academic_year_id', $academicYearId);

        if (! empty($filters['club_id'])) {
            $query->where('club_id', (int) $filters['club_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = '%' . trim($filters['search']) . '%';
            $query->whereHas('student', fn ($q) => $q->where('first_name', 'like', $search)
                ->orWhere('last_name', 'like', $search)
                ->orWhere('student_code', 'like', $search));
        }

        if (! empty($filters['section_id'])) {
            $sectionId = (int) $filters['section_id'];
            $query->whereHas('student.enrollments', fn ($q) => $q->where('academic_year_id', $academicYearId)->where('section_id', $sectionId));
        } elseif (! empty($filters['level_id'])) {
            $levelId = (int) $filters['level_id'];
            $query->whereHas('student.enrollments', fn ($q) => $q->where('academic_year_id', $academicYearId)->where('level_id', $levelId));
        }

        $records = $query->get();

        $enrolledCount = $records->count();
        $paidCount = 0;
        $unpaidCount = 0;
        $partialCount = 0;
        $totalDue = 0.0;
        $totalPaid = 0.0;

        $items = $records->map(function ($row) use (&$paidCount, &$unpaidCount, &$partialCount, &$totalDue, &$totalPaid, $academicYearId) {
            $due = (float) $row->amount_due;
            $paid = (float) $row->amount_paid;
            $remaining = max(0, round($due - $paid, 2));

            $totalDue += $due;
            $totalPaid += $paid;

            if ($row->status === ClubMonthlyFee::STATUS_PAID) {
                $paidCount++;
            } elseif ($row->status === ClubMonthlyFee::STATUS_PARTIAL) {
                $partialCount++;
            } else {
                $unpaidCount++;
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
                'amount_paid' => $paid,
                'remaining' => $remaining,
                'status' => $row->status,
                'status_label' => ClubMonthlyFee::STATUS_LABELS[$row->status] ?? $row->status,
                'status_color' => ClubMonthlyFee::STATUS_COLORS[$row->status] ?? 'gray',
                'paid_at' => $row->paid_at?->toDateString(),
                'method' => $row->method,
                'notes' => $row->notes,
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
                'unpaid_count' => $unpaidCount,
                'partial_count' => $partialCount,
                'total_due' => round($totalDue, 2),
                'total_paid' => round($totalPaid, 2),
                'total_remaining' => $totalRemaining,
            ],
            'records' => $items,
        ];
    }
}
