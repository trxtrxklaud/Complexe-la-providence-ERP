<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\StudentFee;
use App\Models\PaymentAllocation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function recordPayment(array $data, ?int $createdBy = null): Payment
    {
        $key = $data['idempotency_key'] ?? null;

        // إعادة الإرسال: إن وُجدت دفعة بنفس المفتاح نُعيدها بدل إنشاء نسخة ثانية.
        if ($key) {
            $existing = Payment::where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }
        }

        try {
            return DB::transaction(function () use ($data, $createdBy, $key) {
                if (!empty($data['allocations'])) {
                    $allocationsTotal = array_sum(array_column($data['allocations'], 'amount'));
                    if ($allocationsTotal > $data['amount']) {
                        throw new \InvalidArgumentException(
                            'مجموع التوزيعات (' . $allocationsTotal . ') يتجاوز مبلغ الدفعة (' . $data['amount'] . ')'
                        );
                    }

                    foreach ($data['allocations'] as $allocation) {
                        // قفل صف الرسم حتى لا تتجاوز دفعتان متزامنتان المتبقّي معاً.
                        $fee = StudentFee::whereKey($allocation['student_fee_id'])
                            ->lockForUpdate()
                            ->first();

                        if (!$fee) {
                            throw new \InvalidArgumentException(
                                'رسم التلميذ رقم ' . $allocation['student_fee_id'] . ' غير موجود'
                            );
                        }

                        $alreadyAllocated = $fee->paymentAllocations()
                            ->whereHas('payment', fn ($q) => $q->whereNull('cancelled_at'))
                            ->sum('amount_allocated');
                        $remaining = $fee->amount_due - $alreadyAllocated;

                        if ($allocation['amount'] > $remaining) {
                            throw new \InvalidArgumentException(
                                'مبلغ التوزيع (' . $allocation['amount'] . ') يتجاوز المبلغ المتبقي'
                                . ' (' . $remaining . ') للرسم: ' . $fee->description
                            );
                        }
                    }
                }

                $payment = Payment::create([
                    'student_id'      => $data['student_id'],
                    'enrollment_id'   => $data['enrollment_id'] ?? null,
                    'amount'          => $data['amount'],
                    'payment_date'    => $data['payment_date'],
                    'method'          => $data['method'],
                    'reference'       => $data['reference'] ?? null,
                    'notes'           => $data['notes'] ?? null,
                    'idempotency_key' => $key,
                    'created_by'      => $createdBy,
                ]);

                if (!empty($data['allocations'])) {
                    foreach ($data['allocations'] as $allocation) {
                        PaymentAllocation::create([
                            'payment_id'       => $payment->id,
                            'student_fee_id'   => $allocation['student_fee_id'],
                            'amount_allocated' => $allocation['amount'],
                        ]);
                        $this->updateStudentFeeStatus($allocation['student_fee_id']);
                    }
                }

                return $payment;
            });
        } catch (QueryException $e) {
            // سباق تزامن على نفس المفتاح: الفائز أنشأ الدفعة والخاسر يستردّها.
            if ($key && $this->isDuplicateKey($e)) {
                return Payment::where('idempotency_key', $key)->firstOrFail();
            }
            throw $e;
        }
    }

    /**
     * كشف انتهاك قيد الفرادة عبر محركات مختلفة (MySQL/PostgreSQL/SQLite).
     */
    private function isDuplicateKey(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        return in_array($code, ['23000', '23505'], true)
            || str_contains($e->getMessage(), 'idempotency_key')
            || str_contains(strtolower($e->getMessage()), 'unique');
    }

    public function recalculateStudentFeeStatus(int $studentFeeId): void
    {
        $this->updateStudentFeeStatus($studentFeeId);
    }

    private function updateStudentFeeStatus(int $studentFeeId): void
    {
        $fee = StudentFee::find($studentFeeId);
        if (!$fee) return;

        // تُحتسب المخصّصات من الدفعات غير الملغاة فقط، حتى يعود الرسم
        // غير مدفوع تلقائياً عند إلغاء دفعته.
        $allocated = $fee->paymentAllocations()
            ->whereHas('payment', fn ($q) => $q->whereNull('cancelled_at'))
            ->sum('amount_allocated');

        $fee->update([
            'status' => match (true) {
                $allocated >= $fee->amount_due => 'paid',
                $allocated > 0                 => 'partial',
                default                        => 'pending',
            }
        ]);
    }

    public function getStudentBalance(int $studentId): float
    {
        $fees = StudentFee::whereHas('enrollment', fn ($q) =>
            $q->where('student_id', $studentId)
        )
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->with(['paymentAllocations' => fn ($q) =>
                $q->whereHas('payment', fn ($p) => $p->whereNull('cancelled_at'))
            ])
            ->get();

        return (float) $fees->sum(fn ($fee) =>
            max(0, $fee->amount_due - $fee->paymentAllocations->sum('amount_allocated'))
        );
    }
}
