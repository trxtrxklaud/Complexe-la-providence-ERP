<?php

namespace App\Services;

use App\Exceptions\MonthCollectionException;
use App\Models\Enrollment;
use App\Models\ManualStudentDebt;
use App\Models\OpeningBalance;
use App\Models\Payment;
use App\Models\StudentFee;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class MonthCollectionCoreService
{
    /**
     * قفل التسجيل ضد التزامن (lockForUpdate) داخل المعاملة.
     */
    public function lockEnrollment(int $enrollmentId): Enrollment
    {
        return Enrollment::with(['academicYear', 'level', 'student'])
            ->whereKey($enrollmentId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * توحيد صياغة مفتاح الشهر الدراسي إلى الصيغة المعيارية YYYY-MM.
     */
    public function normalizeMonthKey(string $month): string
    {
        $trimmed = trim($month);
        if (preg_match('/^(\d{4})-(0?[1-9]|1[0-2])(-\d{2})?$/', $trimmed, $matches)) {
            return sprintf('%04d-%02d', (int) $matches[1], (int) $matches[2]);
        }

        throw new InvalidArgumentException("صيغة الشهر غير صالحة: {$month}؛ يجب أن تكون بصيغة YYYY-MM.");
    }

    /**
     * الحارس المالي الموحد: التحقق الصارم من أن الأشهر المستهدفة لم يتم استخلاصها مسبقاً عبر أي مسار.
     *
     * @param  Enrollment  $enrollment  التسجيل المقفول مسبقاً بـ lockForUpdate
     * @param  array<string>  $monthKeys  مصفوفة الأشهر المطلوبة
     * @param  int|null  $ignorePaymentId  معرف دفعة مستثناة من الفحص (اختياري)
     *
     * @throws MonthCollectionException في حال وجود تعارض ملكية غير محسوم (UNRESOLVED_MONTH_OWNERSHIP)
     * @throws InvalidArgumentException في حال ثبوت استخلاص الشهر مسبقاً
     * @throws DomainException في حال الاستدعاء خارج معاملة DB::transaction
     */
    public function assertMonthsNotCollected(
        Enrollment $enrollment,
        array $monthKeys,
        ?int $ignorePaymentId = null
    ): void {
        if (DB::transactionLevel() === 0) {
            throw new DomainException('يجب استدعاء حارس التحصيل الشهري داخل معاملة قاعدة بيانات (DB::transaction).');
        }

        if (empty($monthKeys)) {
            return;
        }

        foreach ($monthKeys as $rawMonth) {
            $monthKey = $this->normalizeMonthKey($rawMonth);
            $ownership = $this->findActiveMonthOwnership($enrollment, $monthKey, $ignorePaymentId);

            if ($ownership['is_unresolved']) {
                throw new MonthCollectionException(
                    "تعارض محاسبي غير محسوم في ملكية شهر الدراسة [{$monthKey}]؛ يرفض النظام إنشاء عمليات جديدة حفاظاً على سلامة الخزينة. ({$ownership['unresolved_reason']})",
                    'UNRESOLVED_MONTH_OWNERSHIP',
                    [
                        'enrollment_id' => $enrollment->id,
                        'month_key' => $monthKey,
                        'conflict_ids' => $ownership['conflict_ids'],
                        'reason' => $ownership['unresolved_reason'],
                    ]
                );
            }

            if ($ownership['is_collected']) {
                $paymentId = $ownership['payment_ids'][0] ?? null;
                $ref = $paymentId ? " (الدفعة: #{$paymentId})" : '';
                throw new InvalidArgumentException(
                    "الشهر المطلوب ({$monthKey}) مستخلص مسبقاً لهذا التلميذ{$ref}؛ يمنع النظام تكرار خلاص الشهر مرتين."
                );
            }
        }
    }

    /**
     * فحص مركب لملكية وحالة استخلاص الشهر الدراسي لتسجيل معين.
     *
     * @return array{
     *     is_collected: bool,
     *     is_unresolved: bool,
     *     unresolved_reason: ?string,
     *     enrollment_id: int,
     *     month_key: string,
     *     conflict_ids: array{payment_ids: array<int>, fee_ids: array<int>, allocation_ids: array<int>},
     *     payment_ids: array<int>,
     *     fee_ids: array<int>
     * }
     */
    public function findActiveMonthOwnership(
        Enrollment $enrollment,
        string $monthKey,
        ?int $ignorePaymentId = null
    ): array {
        $normalizedMonth = $this->normalizeMonthKey($monthKey);

        $isCollected = false;
        $isUnresolved = false;
        $unresolvedReason = null;

        $matchingPaymentIds = [];
        $matchingFeeIds = [];
        $conflictPaymentIds = [];
        $conflictFeeIds = [];
        $conflictAllocationIds = [];

        $hasOpeningBalances = Schema::hasTable('opening_balances');
        $hasManualDebts = Schema::hasTable('manual_student_debts');

        // 1. فحص الدفعات النشطة لنفس التسجيل (cancelled_at IS NULL)
        $activePaymentsQuery = Payment::query()
            ->where('enrollment_id', $enrollment->id)
            ->whereNull('cancelled_at');

        if ($ignorePaymentId !== null) {
            $activePaymentsQuery->where('id', '!=', $ignorePaymentId);
        }

        $activePayments = $activePaymentsQuery
            ->with(['paymentAllocations.studentFee'])
            ->lockForUpdate()
            ->get();

        foreach ($activePayments as $payment) {
            $claimsMonth = false;

            // أ) فحص مصفوفة months
            $months = (array) ($payment->months ?? []);
            foreach ($months as $m) {
                try {
                    if ($this->normalizeMonthKey((string) $m) === $normalizedMonth) {
                        $claimsMonth = true;
                        break;
                    }
                } catch (\Throwable) {
                    // تجاهل أي نص غير منسق
                }
            }

            // ب) فحص meta الخاص بالدورة القصيرة
            $meta = is_array($payment->meta) ? $payment->meta : json_decode($payment->meta ?? '[]', true);
            if (! empty($meta['target_months']) && is_array($meta['target_months'])) {
                foreach ($meta['target_months'] as $tm) {
                    try {
                        if ($this->normalizeMonthKey((string) $tm) === $normalizedMonth) {
                            $claimsMonth = true;
                            break;
                        }
                    } catch (\Throwable) {
                    }
                }
            }
            if (! empty($meta['half_rate_month'])) {
                try {
                    if ($this->normalizeMonthKey((string) $meta['half_rate_month']) === $normalizedMonth) {
                        $claimsMonth = true;
                    }
                } catch (\Throwable) {
                }
            }

            if ($claimsMonth) {
                $allAllocations = $payment->paymentAllocations;

                // إذا كانت جميع تخصيصات الدفعة تخص النوادي أو الديون القديمة أو الترسيم/المبيعات، فالدفعة معزولة تماماً ولا تمس التمدرس
                if ($allAllocations->isNotEmpty()) {
                    $hasTuitionAlloc = $allAllocations->contains(function ($alloc) use ($hasOpeningBalances, $hasManualDebts) {
                        $fee = $alloc->studentFee;
                        if (! $fee) {
                            return false;
                        }
                        if ($fee->club_monthly_fee_id !== null) {
                            return false;
                        }
                        if ($hasOpeningBalances && OpeningBalance::where('source_student_fee_id', $fee->id)->exists()) {
                            return false;
                        }
                        if ($hasManualDebts && ManualStudentDebt::where('source_student_fee_id', $fee->id)->exists()) {
                            return false;
                        }
                        if ($fee->feeType && in_array($fee->feeType->ledger_category, ['registration_fee', 'product_sale', 'other_income'], true)) {
                            return false;
                        }

                        return (float) $alloc->amount_allocated > 0;
                    });

                    if (! $hasTuitionAlloc) {
                        // دفعة نوادٍ أو ديون قديمة أو ترسيم معزولة — لا تمس الشهر الدراسي ولا تحجزه
                        continue;
                    }
                }

                // التحقق من وجود تخصيص مالي نشط يخص التمدرس (مع استبعاد النوادي والديون السابقة والترسيم والمبيعات)
                $validTuitionAllocations = $allAllocations->filter(function ($alloc) use ($hasOpeningBalances, $hasManualDebts) {
                    $fee = $alloc->studentFee;
                    if (! $fee) {
                        return false;
                    }
                    if ($fee->club_monthly_fee_id !== null) {
                        return false; // استبعاد النوادي
                    }
                    if ($hasOpeningBalances && OpeningBalance::where('source_student_fee_id', $fee->id)->exists()) {
                        return false; // استبعاد الأرصدة الافتتاحية
                    }
                    if ($hasManualDebts && ManualStudentDebt::where('source_student_fee_id', $fee->id)->exists()) {
                        return false; // استبعاد ديون السنوات السابقة
                    }
                    if ($fee->feeType && in_array($fee->feeType->ledger_category, ['registration_fee', 'product_sale', 'other_income'], true)) {
                        return false; // استبعاد رسوم الترسيم ومبيعات المنتجات والمداخيل الأخرى
                    }

                    return (float) $alloc->amount_allocated > 0;
                });

                if ($validTuitionAllocations->isEmpty()) {
                    // تناقض صريح: الدفعة توثق الشهر في months دون وجود أي تخصيص مالي في payment_allocations
                    $isUnresolved = true;
                    $unresolvedReason = "الدفعة #{$payment->id} تسجل الشهر ({$normalizedMonth}) في حقل months دون وجود تخصيص مالي متوافق في جدول payment_allocations.";
                    $conflictPaymentIds[] = $payment->id;
                } else {
                    $isCollected = true;
                    $matchingPaymentIds[] = $payment->id;
                    foreach ($validTuitionAllocations as $alloc) {
                        $matchingFeeIds[] = $alloc->student_fee_id;
                    }
                }
            }
        }

        // 2. فحص رسوم التمدرس القائمة لنفس التسجيل (مع استبعاد تام للنوادي والديون السابقة ورسوم الترسيم والمبيعات والمداخيل الأخرى)
        $tuitionFeesQuery = StudentFee::query()
            ->where('enrollment_id', $enrollment->id)
            ->whereNull('club_monthly_fee_id')
            ->whereDoesntHave('feeType', function ($ftQuery) {
                $ftQuery->whereIn('ledger_category', ['registration_fee', 'product_sale', 'other_income']);
            });

        if ($hasOpeningBalances) {
            $tuitionFeesQuery->whereNotIn('id', function ($sub) {
                $sub->select('source_student_fee_id')->from('opening_balances')->whereNotNull('source_student_fee_id');
            });
        }
        if ($hasManualDebts) {
            $tuitionFeesQuery->whereNotIn('id', function ($sub) {
                $sub->select('source_student_fee_id')->from('manual_student_debts')->whereNotNull('source_student_fee_id');
            });
        }

        $monthStart = "{$normalizedMonth}-01";
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $tuitionFeesQuery->where(function ($q) use ($monthStart, $monthEnd) {
            $q->whereDate('due_date', '>=', $monthStart)
              ->whereDate('due_date', '<=', $monthEnd);
        })->with(['paymentAllocations.payment'])
          ->lockForUpdate();

        $tuitionFees = $tuitionFeesQuery->get();

        foreach ($tuitionFees as $fee) {
            $activeAllocations = $fee->paymentAllocations->filter(function ($alloc) use ($ignorePaymentId) {
                $p = $alloc->payment;
                if (! $p || $p->cancelled_at !== null) {
                    return false;
                }
                if ($ignorePaymentId !== null && (int) $p->id === $ignorePaymentId) {
                    return false;
                }

                return (float) $alloc->amount_allocated > 0;
            });

            // إذا كان الرسم مسدداً (paid أو متبقيه صفر)
            if ($fee->status === 'paid' || (float) $fee->outstanding() <= 0.001) {
                if ($activeAllocations->isEmpty()) {
                    // تناقض: الرسم يحمل حالة مدفوع لكن لا توجد أي دفعة نشطة مرتبطة به
                    $isUnresolved = true;
                    $unresolvedReason = "الرسم #{$fee->id} مسجل بحالة مدفوع (paid) دون وجود أي دفعة نشطة مرتبطة به في payment_allocations.";
                    $conflictFeeIds[] = $fee->id;
                } else {
                    $isCollected = true;
                    $matchingFeeIds[] = $fee->id;
                    foreach ($activeAllocations as $alloc) {
                        $matchingPaymentIds[] = $alloc->payment_id;
                    }
                }
            } elseif ($activeAllocations->isNotEmpty()) {
                // رسم به دفعات نشطة قائمة
                $isCollected = true;
                $matchingFeeIds[] = $fee->id;
                foreach ($activeAllocations as $alloc) {
                    $matchingPaymentIds[] = $alloc->payment_id;
                }
            }
        }

        return [
            'is_collected' => $isCollected,
            'is_unresolved' => $isUnresolved,
            'unresolved_reason' => $unresolvedReason,
            'enrollment_id' => $enrollment->id,
            'month_key' => $normalizedMonth,
            'conflict_ids' => [
                'payment_ids' => array_values(array_unique($conflictPaymentIds)),
                'fee_ids' => array_values(array_unique($conflictFeeIds)),
                'allocation_ids' => array_values(array_unique($conflictAllocationIds)),
            ],
            'payment_ids' => array_values(array_unique($matchingPaymentIds)),
            'fee_ids' => array_values(array_unique($matchingFeeIds)),
        ];
    }
}
