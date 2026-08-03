<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\StudentFee;

/**
 * معلوم الترسيم المقبوض لحظة تسجيل تلميذ جديد.
 *
 * كان هذا المبلغ يُجمع في الواجهة ثمّ يُرمى: المتحكّم لم يكن يتحقّق منه أصلاً،
 * فلا يظهر في الخزينة ولا في المداخيل ولا في الدخل الصافي، ويبقى رسم الترسيم مفتوحاً
 * على التلميذ رغم دفع وليّه. هذه الخدمة تغلق الثغرة.
 *
 * التخصيص مقصود: المبلغ يُوزّع أوّلاً على الرسوم السنوية (معلوم الترسيم)،
 * لأنّ LedgerService يشتقّ بند المدخول من خطة الرسم: yearly ← registration_fee.
 * دفعة بلا توزيع تسقط في «مداخيل أخرى» — رقم صحيح في خانة خاطئة.
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
     * توزيع المبلغ على الرسوم السنوية المفتوحة دون تجاوز المتبقّي.
     *
     * @return array<int,array{student_fee_id:int,amount:float}>
     */
    private function allocations(Enrollment $enrollment, float $amount): array
    {
        $fees = StudentFee::where('enrollment_id', $enrollment->id)
            ->whereHas('feePlan', fn ($query) => $query->where('frequency', 'yearly'))
            ->orderBy('due_date')
            ->get();

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
}
