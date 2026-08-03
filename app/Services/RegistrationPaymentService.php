<?php

namespace App\Services;

use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\StudentFee;

/**
 * معلوم الترسيم المقبوض لحظة تسجيل تلميذ جديد.
 *
 * التصنيف ليس تفصيلاً تجميلياً: LedgerService لا يعرف أنّ الدفعة معلوم ترسيم، بل
 * يستنتج البند من الرسم المُخصّص لها. دفعة بلا تخصيص تسقط في «مداخيل أخرى»،
 * فيدخل المال الخزينة ويغيب عن معاليم التسجيل وعن كل تقرير يفصّل حسب البند.
 *
 * لذلك نضمن وجود رسم يُخصّص له المبلغ، بترتيب أولوية واضح:
 *   1) رسوم خطة سنوية (yearly) إن وُلّدت للمستوى — وهي الحالة المثلى
 *   2) وإلا فرسم مربوط بنوع رسم مُصنّف registration_fee يُنشَأ عند الحاجة
 *
 * لماذا نُنشئ رسماً؟ لأنّ المدرسة قبضت مالاً مقابل الترسيم، فوجب أن يظهر في
 * ملفّ التلميذ ما قُبض ومقابل ماذا. دفعة بلا رسم هي مال بلا سبب في دفتر محاسبي.
 */
class RegistrationPaymentService
{
    public function __construct(private PaymentService $payments) {}

    /**
     * @param  array<string,mixed>  $data  الحقول المتحقّق منها القادمة من شاشة التسجيل.
     */
    public function record(Enrollment $enrollment, array $data, ?int $createdBy = null): ?Payment
    {
        $amount = round((float) ($data['registration_amount'] ?? 0), 2);

        if ($amount <= 0) {
            return null;
        }

        return $this->payments->recordPayment([
            'student_id'      => $enrollment->student_id,
            'enrollment_id'   => $enrollment->id,
            'amount'          => $amount,
            'payment_date'    => $data['payment_date'] ?? now()->toDateString(),
            'method'          => $data['payment_method'] ?? 'cash',
            'notes'           => $data['payment_notes'] ?? 'معلوم الترسيم عند التسجيل',
            // مفتاح ثابت لكل ترسيم: إعادة إرسال النموذج لا تضاعف المدخول.
            'idempotency_key' => 'enrollment-' . $enrollment->id . '-registration',
            'allocations'     => $this->allocations($enrollment, $amount),
        ], $createdBy);
    }

    /**
     * توزيع المبلغ على رسوم الترسيم المفتوحة دون تجاوز المتبقّي.
     *
     * @return array<int,array{student_fee_id:int,amount:float}>
     */
    private function allocations(Enrollment $enrollment, float $amount): array
    {
        $fees = $this->registrationFees($enrollment, $amount);

        $allocations = [];
        $remaining   = $amount;

        foreach ($fees as $fee) {
            if ($remaining <= 0) {
                break;
            }

            $allocated = (float) $fee->paymentAllocations()
                ->whereHas('payment', fn ($query) => $query->whereNull('cancelled_at'))
                ->sum('amount_allocated');

            $due = round((float) $fee->amount_due - $allocated, 2);

            if ($due <= 0) {
                continue;
            }

            $take = min($due, $remaining);

            $allocations[] = [
                'student_fee_id' => $fee->id,
                'amount'         => $take,
            ];

            $remaining = round($remaining - $take, 2);
        }

        return $allocations;
    }

    /**
     * رسوم الترسيم المتاحة لهذا الترسيم، مع إنشاء رسم عند الحاجة.
     *
     * @return \Illuminate\Support\Collection<int,StudentFee>
     */
    private function registrationFees(Enrollment $enrollment, float $amount)
    {
        $planned = StudentFee::where('enrollment_id', $enrollment->id)
            ->whereHas('feePlan', fn ($query) => $query->where('frequency', 'yearly'))
            ->orderBy('due_date')
            ->get();

        if ($planned->isNotEmpty()) {
            return $planned;
        }

        $feeType = $this->registrationFeeType();

        if (! $feeType) {
            // لا نوع رسم مصنّف للترسيم: نترك المبلغ يدخل الخزينة كمدخول آخر
            // بدل رفض الترسيم. إخفاء مال مقبوض أخطر من تصنيفه تصنيفاً عامّاً.
            return collect();
        }

        $price = (float) $feeType->price;

        $fee = StudentFee::firstOrCreate(
            [
                'enrollment_id' => $enrollment->id,
                'fee_type_id'   => $feeType->id,
            ],
            [
                'description' => $feeType->name_ar ?: 'معلوم الترسيم',
                'amount_due'  => $price > 0 ? $price : $amount,
                'due_date'    => $enrollment->enrollment_date ?? now()->toDateString(),
                'status'      => 'pending',
            ]
        );

        return collect([$fee]);
    }

    /**
     * نوع الرسم المعتمد للترسيم: المُصرّح بـ ledger_category أوّلاً،
     * ثمّ أي نوع يستنتج من اسمه أنّه ترسيم (للأنواع القديمة قبل إضافة العمود).
     */
    private function registrationFeeType(): ?FeeType
    {
        $declared = FeeType::where('is_active', true)
            ->where('ledger_category', CashTransaction::CATEGORY_REGISTRATION_FEE)
            ->orderBy('id')
            ->first();

        if ($declared) {
            return $declared;
        }

        return FeeType::where('is_active', true)
            ->orderBy('id')
            ->get()
            ->first(fn (FeeType $type) => $type->resolveLedgerCategory() === CashTransaction::CATEGORY_REGISTRATION_FEE);
    }
}
