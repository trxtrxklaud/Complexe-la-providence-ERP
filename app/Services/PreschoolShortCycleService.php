<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\StudentFee;
use Illuminate\Support\Facades\DB;

/**
 * @deprecated Legacy standalone service for preschool short cycle collection.
 * The core logic has been unified into CollectionService using manual_amount/manual_amounts.
 * PreschoolShortCycleService::calculateHalfRate() remains the canonical helper for default half rates.
 * Kept for backwards compatibility and concurrency test verification until post-UAT cleanup.
 */
class PreschoolShortCycleService
{
    public const ALLOWED_LEVELS = ['PRE1', 'PRE2', 'PRE3'];

    /**
     * حساب نصف معلوم التمدرس الشهري المعتمد لمرحلة ما قبل الابتدائي (PRE1, PRE2, PRE3).
     */
    public static function calculateHalfRate(float $fullRate): float
    {
        return round($fullRate / 2, 3);
    }

    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly LedgerService $ledgerService,
        private readonly MonthCollectionCoreService $monthCoreService,
    ) {}

    /**
     * تنفيذ استخلاص دورة سبتمبر / جوان المبسطة لأقسام التحضيري والروضة والتمهيدي.
     */
    public function collect(array $data, int $userId): array
    {
        return DB::transaction(function () use ($data, $userId) {
            $enrollmentId = (int) $data['enrollment_id'];

            // 1. قفل التسجيل ضد التزامن (lockForUpdate)
            $enrollment = Enrollment::with(['academicYear', 'level', 'student'])
                ->whereKey($enrollmentId)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. فحص مفتاح منع التكرار (Idempotency) بعد أخذ القفل
            $idempotencyKey = ! empty($data['idempotency_key']) ? trim((string) $data['idempotency_key']) : null;
            if ($idempotencyKey !== null) {
                $existingPayment = Payment::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existingPayment) {
                    return $this->formatReceiptResponse($existingPayment);
                }
            }

            // 3. التحقق الصارم من المستوى (PRE1, PRE2, PRE3 فقط)
            $levelCode = $enrollment->level?->code;
            if (! in_array($levelCode, self::ALLOWED_LEVELS, true)) {
                throw new \InvalidArgumentException('هذا المسار مخصص حصراً لأقسام الروضة والتمهيدي والتحضيري (PRE1, PRE2, PRE3).');
            }

            // 4. التحقق من أن السنة الأكاديمية نشطة
            $academicYear = $enrollment->academicYear;
            if (! $academicYear || ! $academicYear->is_active) {
                throw new \InvalidArgumentException('السنة الدراسية للتسجيل غير نشطة.');
            }

            // 5. استرجاع FeePlan الشهري الرسمي للمستوى والسنة
            $plans = FeePlan::where('academic_year_id', $academicYear->id)
                ->where('level_id', $enrollment->level_id)
                ->where('frequency', 'monthly')
                ->get();

            if ($plans->count() !== 1) {
                throw new \DomainException('يجب توفر خطة رسوم شهرية رسمية واحدة ومحددة لهذا المستوى.');
            }

            $feePlan = $plans->first();
            $fullRate = (float) $feePlan->amount;
            $halfRate = self::calculateHalfRate($fullRate);

            // 6. تحديد الأشهر المستهدفة وتواريخ الاستحقاق
            $startYear = (int) $academicYear->start_date->format('Y');
            $septemberMonth = sprintf('%04d-09', $startYear);
            $juneMonth = sprintf('%04d-06', $startYear + 1);

            $septemberDueDate = sprintf('%04d-09-01', $startYear);
            $juneDueDate = sprintf('%04d-06-01', $startYear + 1);

            $cycleMode = $data['cycle_mode'];
            $halfRateMonth = $data['half_rate_month'] ?? null;

            $targetMonths = [];
            if ($cycleMode === 'half_rate') {
                if (! in_array($halfRateMonth, [$septemberMonth, $juneMonth], true)) {
                    throw new \InvalidArgumentException('الشهر المختار لنصف المعلوم يجب أن يكون حصراً شهر سبتمبر ('.$septemberMonth.') أو شهر جوان ('.$juneMonth.').');
                }
                $targetMonths = [$halfRateMonth];
            } elseif ($cycleMode === 'full_rate') {
                if (! empty($halfRateMonth)) {
                    throw new \InvalidArgumentException('لا يجوز تحديد شهر لنصف المعلوم عند اختيار المعلوم الكامل.');
                }
                $targetMonths = [$septemberMonth, $juneMonth];
            } else {
                throw new \InvalidArgumentException('وضع التحصيل غير صالح.');
            }

            // 7. فحص الرسوم القائمة بمفتاح مقفل مع استبعاد تام لرسوم النوادي
            $existingFees = StudentFee::where('enrollment_id', $enrollment->id)
                ->where('fee_plan_id', $feePlan->id)
                ->whereNull('club_monthly_fee_id')
                ->where(function ($q) use ($septemberDueDate, $juneDueDate) {
                    $q->whereDate('due_date', $septemberDueDate)
                      ->orWhereDate('due_date', $juneDueDate);
                })
                ->lockForUpdate()
                ->get()
                ->keyBy(fn ($f) => $f->due_date->format('Y-m-d'));

            $septemberFee = $existingFees->get($septemberDueDate);
            $juneFee = $existingFees->get($juneDueDate);

            $sepOwnership = $this->monthCoreService->findActiveMonthOwnership($enrollment, $septemberMonth);
            $junOwnership = $this->monthCoreService->findActiveMonthOwnership($enrollment, $juneMonth);

            $isSeptemberPaid = $sepOwnership['is_collected'] || $this->isFeeSettled($septemberFee);
            $isJunePaid = $junOwnership['is_collected'] || $this->isFeeSettled($juneFee);

            if ($cycleMode === 'full_rate') {
                if ($isSeptemberPaid && $isJunePaid) {
                    throw new \InvalidArgumentException('شهرا سبتمبر وجوان مستخلصان بالكامل مسبقاً.');
                }
                if ($isSeptemberPaid) {
                    throw new \InvalidArgumentException('شهر سبتمبر مستخلص مسبقاً؛ يرجى اختيار نصف معلوم لشهر جوان.');
                }
                if ($isJunePaid) {
                    throw new \InvalidArgumentException('شهر جوان مستخلص مسبقاً؛ يرجى اختيار نصف معلوم لشهر سبتمبر.');
                }
            } elseif ($cycleMode === 'half_rate') {
                if ($halfRateMonth === $septemberMonth && $isSeptemberPaid) {
                    throw new \InvalidArgumentException('شهر سبتمبر مستخلص مسبقاً لهذا التلميذ.');
                }
                if ($halfRateMonth === $juneMonth && $isJunePaid) {
                    throw new \InvalidArgumentException('شهر جوان مستخلص مسبقاً لهذا التلميذ.');
                }
            }

            // 8. استدعاء الحارس المالي الموحد للتأكيد الصارم وفحص حالات التعارض غير المحسومة
            $this->monthCoreService->assertMonthsNotCollected($enrollment, $targetMonths);

            // 8. تجهيز أو إنشاء الرسوم المطلوبة
            $feesToCollect = [];
            foreach ($targetMonths as $month) {
                $dueDate = ($month === $septemberMonth) ? $septemberDueDate : $juneDueDate;
                $monthName = ($month === $septemberMonth) ? 'سبتمبر' : 'جوان';
                $fee = ($month === $septemberMonth) ? $septemberFee : $juneFee;

                if ($fee) {
                    // إذا كان الرسم موجوداً: تحقق من عدم وجود تخصيصات نشطة أو إعفاءات
                    if ($this->hasAnyActiveAllocationsOrWaivers($fee)) {
                        throw new \InvalidArgumentException("شهر {$monthName} مرتبط بتخصيصات أو إعفاءات نشطة؛ لا يمكن استخلاصه عبر هذا المسار.");
                    }
                    // التحقق من أن الرسم القائم ليس ديناً قديماً أو جسراً لمديونية يدوية
                    $hasOpeningTable = \Illuminate\Support\Facades\Schema::hasTable('opening_balances');
                    $hasDebtsTable = \Illuminate\Support\Facades\Schema::hasTable('manual_student_debts');
                    $isPriorDebt = ($hasOpeningTable && \App\Models\OpeningBalance::where('source_student_fee_id', $fee->id)->exists())
                        || ($hasDebtsTable && \App\Models\ManualStudentDebt::where('source_student_fee_id', $fee->id)->exists());

                    if ($isPriorDebt) {
                        throw new \InvalidArgumentException("شهر {$monthName} مرتبط برصيد افتتاحي أو دَين قديم، ولا يمكن استخلاصه عبر هذا المسار.");
                    }
                    // التحقق الصارم من أن مبلغ الرسم القائم يطابق تماماً نصف المعلوم الرسمي
                    if (abs((float) $fee->amount_due - $halfRate) > 0.001) {
                        throw new \InvalidArgumentException("شهر {$monthName} يحتوي على رسم قائم بمبلغ مختلف عن نصف المعلوم الرسمي ({$halfRate} د.ت).");
                    }
                } else {
                    $fee = StudentFee::create([
                        'enrollment_id'       => $enrollment->id,
                        'fee_plan_id'         => $feePlan->id,
                        'fee_type_id'         => null,
                        'club_monthly_fee_id' => null,
                        'amount_due'          => $halfRate,
                        'direct_paid_amount'  => 0,
                        'due_date'            => $dueDate,
                        'description'         => 'القسط الشهري — '.$monthName.' (نصف شهر)',
                        'status'              => 'pending',
                    ]);
                }
                $feesToCollect[] = $fee;
            }

            // 9. إنشاء سند الدفع (Payment)
            $totalPaymentAmount = ($cycleMode === 'full_rate') ? $fullRate : $halfRate;

            $payment = Payment::create([
                'student_id'      => $enrollment->student_id,
                'enrollment_id'   => $enrollment->id,
                'months'          => $targetMonths,
                'amount'          => $totalPaymentAmount,
                'payment_date'    => $data['payment_date'],
                'method'          => $data['method'],
                'reference'       => $data['reference'] ?? null,
                'notes'           => $data['notes'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'meta'            => [
                    'type'          => 'preschool_short_cycle',
                    'cycle_mode'    => $cycleMode,
                    'level_code'    => $levelCode,
                    'target_months' => $targetMonths,
                    'half_rate'     => $halfRate,
                    'full_rate'     => $fullRate,
                ],
                'created_by'      => $userId,
            ]);

            // 10. إنشاء التخصيصات ومزامنة حالة الرسوم
            foreach ($feesToCollect as $fee) {
                PaymentAllocation::create([
                    'payment_id'       => $payment->id,
                    'student_fee_id'   => $fee->id,
                    'amount_allocated' => $halfRate,
                ]);

                $this->paymentService->recalculateStudentFeeStatus($fee->id);
            }

            // 11. تسجيل الأثر المالي في الخزينة عبر LedgerService
            $this->ledgerService->recordPayment($payment);

            // 12. التحقق المالي الصارم (Assertion) لسلامة قيد الخزينة
            $txs = CashTransaction::where('source_type', Payment::class)
                ->where('source_id', $payment->id)
                ->whereNull('cancelled_at')
                ->get();

            if ($txs->count() !== 1) {
                throw new \DomainException('فشل التحقق المالي: يجب إنشاء قيد خزينة واحد فقط للدفعة.');
            }

            $tx = $txs->first();
            if ($tx->category !== CashTransaction::CATEGORY_MONTHLY_FEE) {
                throw new \DomainException("فشل التحقق المالي: فئة الخزينة المتوقعة 'monthly_fee' ولكن تم تسجيل '{$tx->category}'.");
            }

            if (abs((float) $tx->amount - $totalPaymentAmount) > 0.001) {
                throw new \DomainException('فشل التحقق المالي: مبلغ قيد الخزينة لا يطابق مبلغ الدفعة.');
            }

            if ($tx->direction !== CashTransaction::DIRECTION_IN) {
                throw new \DomainException('فشل التحقق المالي: اتجاه حركة الخزينة غير صحيح.');
            }

            return $this->formatReceiptResponse($payment);
        });
    }

    /**
     * معاينة دورة سبتمبر / جوان للتلميذ وتوضيح الأشهر المتاحة والأسعار الرسمية.
     */
    public function preview(int $enrollmentId): array
    {
        $enrollment = Enrollment::with(['academicYear', 'level', 'student'])
            ->findOrFail($enrollmentId);

        $levelCode = $enrollment->level?->code;
        $isPreschool = in_array($levelCode, self::ALLOWED_LEVELS, true);

        if (! $isPreschool) {
            return [
                'is_preschool' => false,
                'message'      => 'هذا المسار مخصص حصراً لأقسام الروضة والتمهيدي والتحضيري (PRE1, PRE2, PRE3).',
            ];
        }

        $academicYear = $enrollment->academicYear;
        if (! $academicYear || ! $academicYear->is_active) {
            return [
                'is_preschool'    => true,
                'is_active_year'  => false,
                'message'         => 'السنة الدراسية للتسجيل غير نشطة.',
            ];
        }

        $feePlan = FeePlan::where('academic_year_id', $academicYear->id)
            ->where('level_id', $enrollment->level_id)
            ->where('frequency', 'monthly')
            ->first();

        if (! $feePlan) {
            return [
                'is_preschool'     => true,
                'fee_plan_missing' => true,
                'message'          => 'لا توجد خطة رسوم شهرية محددة لهذا المستوى.',
            ];
        }

        $fullRate = (float) $feePlan->amount;
        $halfRate = self::calculateHalfRate($fullRate);

        $startYear = (int) $academicYear->start_date->format('Y');
        $septemberMonth = sprintf('%04d-09', $startYear);
        $juneMonth = sprintf('%04d-06', $startYear + 1);

        $septemberDueDate = sprintf('%04d-09-01', $startYear);
        $juneDueDate = sprintf('%04d-06-01', $startYear + 1);

        $fees = StudentFee::where('enrollment_id', $enrollment->id)
            ->where('fee_plan_id', $feePlan->id)
            ->whereNull('club_monthly_fee_id')
            ->where(function ($q) use ($septemberDueDate, $juneDueDate) {
                $q->whereDate('due_date', $septemberDueDate)
                  ->orWhereDate('due_date', $juneDueDate);
            })
            ->get()
            ->keyBy(fn ($f) => $f->due_date->format('Y-m-d'));

        $sepOwnership = $this->monthCoreService->findActiveMonthOwnership($enrollment, $septemberMonth);
        $junOwnership = $this->monthCoreService->findActiveMonthOwnership($enrollment, $juneMonth);

        $septemberPaid = $sepOwnership['is_collected'] || $this->isFeeSettled($fees->get($septemberDueDate));
        $junePaid = $junOwnership['is_collected'] || $this->isFeeSettled($fees->get($juneDueDate));

        return [
            'is_preschool'          => true,
            'level_code'            => $levelCode,
            'level_name'            => $enrollment->level?->name,
            'student_id'            => $enrollment->student_id,
            'student_name'          => $enrollment->student?->first_name . ' ' . $enrollment->student?->last_name,
            'student_code'          => $enrollment->student?->student_code,
            'academic_year_id'      => $academicYear->id,
            'fee_plan_id'           => $feePlan->id,
            'full_rate'             => $fullRate,
            'half_rate'             => $halfRate,
            'september'             => [
                'month'    => $septemberMonth,
                'due_date' => $septemberDueDate,
                'is_paid'  => $septemberPaid,
                'amount'   => $halfRate,
            ],
            'june'                  => [
                'month'    => $juneMonth,
                'due_date' => $juneDueDate,
                'is_paid'  => $junePaid,
                'amount'   => $halfRate,
            ],
            'can_collect_full'      => (! $septemberPaid && ! $junePaid),
            'can_collect_september' => ! $septemberPaid,
            'can_collect_june'      => ! $junePaid,
        ];
    }

    private function isFeeSettled(?StudentFee $fee): bool
    {
        if (! $fee) {
            return false;
        }

        if ($fee->status === 'paid') {
            return true;
        }

        $activeAllocations = (float) $fee->paymentAllocations()
            ->whereHas('payment', fn ($q) => $q->whereNull('cancelled_at'))
            ->sum('amount_allocated');

        $activeWaivers = (float) $fee->waivers()
            ->whereNull('cancelled_at')
            ->sum('amount');

        return ($activeAllocations + $activeWaivers) >= (float) $fee->amount_due;
    }

    private function hasAnyActiveAllocationsOrWaivers(?StudentFee $fee): bool
    {
        if (! $fee) {
            return false;
        }

        $hasAlloc = $fee->paymentAllocations()
            ->whereHas('payment', fn ($q) => $q->whereNull('cancelled_at'))
            ->exists();

        $hasWaiver = $fee->waivers()
            ->whereNull('cancelled_at')
            ->exists();

        return $hasAlloc || $hasWaiver;
    }

    private function formatReceiptResponse(Payment $payment): array
    {
        $payment->loadMissing([
            'student:id,first_name,last_name,student_code',
            'enrollment.level:id,code,name',
            'enrollment.academicYear:id,name',
            'paymentAllocations.studentFee',
        ]);

        $receiptItems = [];
        foreach ($payment->paymentAllocations as $alloc) {
            $fee = $alloc->studentFee;
            $receiptItems[] = [
                'student_fee_id'   => $alloc->student_fee_id,
                'description'      => $fee?->description,
                'due_date'         => $fee?->due_date?->format('Y-m-d'),
                'amount_allocated' => (float) $alloc->amount_allocated,
            ];
        }

        return [
            'payment_id'    => $payment->id,
            'student_id'    => $payment->student_id,
            'student_name'  => $payment->student?->first_name . ' ' . $payment->student?->last_name,
            'student_code'  => $payment->student?->student_code,
            'level_code'    => $payment->enrollment?->level?->code,
            'amount'        => (float) $payment->amount,
            'payment_date'  => $payment->payment_date?->format('Y-m-d'),
            'method'        => $payment->method,
            'reference'     => $payment->reference,
            'months'        => $payment->months,
            'items'         => $receiptItems,
            'meta'          => $payment->meta,
        ];
    }
}