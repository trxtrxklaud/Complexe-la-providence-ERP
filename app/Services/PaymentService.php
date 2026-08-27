<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\StudentFee;
use App\Models\PaymentAllocation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(private readonly LedgerService $ledger) {}

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
                        // قفل صف الرسم حتّى لا تتجاوز دفعتان متزامنتان المتبقّي معاً.
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

                        // المتنازل عنه ليس محلّاً للقبض: من أُعفي من 50 د لا يُطالَب بها،
                        // فتُخصم من المتبقّي المسموح توزيعه.
                        $waived = $fee->waivers()->whereNull('cancelled_at')->sum('amount');

                        $remaining = round((float) $fee->amount_due - (float) $alreadyAllocated - (float) $fee->directPaidAmount() - (float) $waived, 2);

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

                // إسقاط الدفعة في الدفتر النقدي المركزي داخل نفس المعاملة،
                // فإمّا أن تُسجّل الدفعة وأثرها النقدي معاً أو لا يُسجّل شيء.
                $this->ledger->recordPayment($payment);

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

        // تُحتسب المخصّصات من الدفعات غير الملغاة فقط، حتّى يعود الرسم
        // غير مدفوع تلقائياً عند إلغاء دفعته.
        $allocated = (float) $fee->paymentAllocations()
            ->whereHas('payment', fn ($q) => $q->whereNull('cancelled_at'))
            ->sum('amount_allocated');

        // وكذلك التنازلات السارية: رسم تُنوزِل عن متبقّيه مقفل، وإلغاء
        // التنازل يعيد الدَّين تلقائياً. لا قيمة waived في الحالات المتاحة،
        // فالمقفل يُخزّن paid وسجلّ fee_waivers هو من يوثّق أنّه لم يكن نقداً.
        $waived = (float) $fee->waivers()->whereNull('cancelled_at')->sum('amount');

        $totalPaid = $allocated + $fee->directPaidAmount();
        $status = match (true) {
            $totalPaid + $waived >= (float) $fee->amount_due => 'paid',
            $totalPaid > 0 => 'partial',
            default => 'pending',
        };

        $fee->update(['status' => $status]);

        if ($fee->club_monthly_fee_id && $clubFee = $fee->clubMonthlyFee()->first()) {
            $clubStatus = match (true) {
                $totalPaid >= (float) $clubFee->amount_due => \App\Models\ClubMonthlyFee::STATUS_PAID,
                $totalPaid > 0 => \App\Models\ClubMonthlyFee::STATUS_PARTIAL,
                default => \App\Models\ClubMonthlyFee::STATUS_UNPAID,
            };
            $clubFee->update([
                'amount_paid' => number_format($totalPaid, 2, '.', ''),
                'status' => $clubStatus,
            ]);
        }
    }

    public function getStudentBalance(int $studentId): float
    {
        $fees = StudentFee::whereHas('enrollment', fn ($q) =>
            $q->where('student_id', $studentId)
        )
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->with([
                'paymentAllocations' => fn ($q) =>
                    $q->whereHas('payment', fn ($p) => $p->whereNull('cancelled_at')),
                'waivers' => fn ($q) => $q->whereNull('cancelled_at'),
            ])
            ->get();

        return (float) $fees->sum(fn ($fee) =>
            max(0, $fee->amount_due - $fee->paymentAllocations->sum('amount_allocated') - $fee->directPaidAmount() - $fee->waivers->sum('amount'))
        );
    }
}
