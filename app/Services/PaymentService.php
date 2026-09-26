<?php

namespace App\Services;

use App\Models\CashTransaction;
use App\Models\FeeType;
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

    /**
     * تصحيح مبلغ الوصل نفسه (Correction).
     *
     * - نفس Payment ونفس CashTransaction.
     * - التاريخ النقدي يبقى تاريخ القبض الأصلي ($payment->payment_date)، حتى لو تم التعديل في يوم لاحق.
     * - لا يُنشأ قيد ثانٍ لنفس التصحيح.
     * - الخزينة في اليوم الأصلي تتغير إلى المبلغ الجديد.
     * - احفظ old_amount وnew_amount وedited_by وedited_at.
     * - التعديل المكرر بنفس المبلغ لا يغيّر شيئاً (Idempotent).
     */
    public function correctPayment(Payment $payment, array $data, ?int $userId = null): Payment
    {
        if ($payment->isCancelled()) {
            throw new \InvalidArgumentException('لا يمكن تصحيح وصل ملغى.');
        }

        $newAmount = round((float) ($data['amount'] ?? 0), 2);
        if ($newAmount <= 0) {
            throw new \InvalidArgumentException('مبلغ التصحيح يجب أن يكون أكبر من صفر.');
        }

        $oldAmount = round((float) $payment->amount, 2);

        // التعديل المكرر بنفس المبلغ لا يغيّر شيئاً
        if (abs($oldAmount - $newAmount) < 0.001 && empty($data['allocations']) && empty($data['items'])) {
            return $payment;
        }

        return DB::transaction(function () use ($payment, $oldAmount, $newAmount, $data, $userId) {
            $payment->loadMissing(['paymentAllocations.studentFee']);

            // 1. تسجيل التدقيق (Audit)
            $meta = $payment->meta ?? [];
            $edits = $meta['edits'] ?? [];
            $edits[] = [
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                'edited_by'  => $userId,
                'edited_at'  => now()->toIso8601String(),
                'notes'      => $data['notes'] ?? $data['reason'] ?? null,
            ];
            $meta['edits'] = $edits;
            $meta['old_amount'] = $oldAmount;
            $meta['new_amount'] = $newAmount;
            $meta['edited_by']  = $userId;
            $meta['edited_at']  = now()->toIso8601String();

            $payment->update([
                'amount'     => $newAmount,
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                'edited_by'  => $userId,
                'edited_at'  => now(),
                'notes'      => isset($data['notes']) ? $data['notes'] : $payment->notes,
                'meta'       => $meta,
            ]);

            // 2. تحديث التوزيعات المالية (Allocations)
            $allocations = $payment->paymentAllocations;

            if (! empty($data['allocations']) && is_array($data['allocations'])) {
                foreach ($data['allocations'] as $allocData) {
                    $allocId = $allocData['id'] ?? null;
                    $itemAmount = round((float) ($allocData['amount'] ?? 0), 2);
                    if ($allocId) {
                        $alloc = $allocations->firstWhere('id', $allocId);
                        if ($alloc) {
                            $alloc->update(['amount_allocated' => $itemAmount]);
                            $fee = $alloc->studentFee;
                            if ($fee && $fee->paymentAllocations()->count() === 1) {
                                $fee->update(['amount_due' => $itemAmount]);
                            }
                            if ($fee) {
                                $this->updateStudentFeeStatus($fee->id);
                            }
                        }
                    }
                }
            } elseif ($allocations->count() === 1) {
                $singleAlloc = $allocations->first();
                $singleAlloc->update(['amount_allocated' => $newAmount]);
                $fee = $singleAlloc->studentFee;
                if ($fee) {
                    if ($fee->paymentAllocations()->count() === 1) {
                        $fee->update(['amount_due' => $newAmount]);
                    }
                    $this->updateStudentFeeStatus($fee->id);
                }
            } elseif ($allocations->isNotEmpty()) {
                $currentAllocSum = (float) $allocations->sum('amount_allocated');
                if ($currentAllocSum > 0 && abs($currentAllocSum - $newAmount) > 0.001) {
                    $diff = $newAmount - $currentAllocSum;
                    $lastAlloc = $allocations->last();
                    $newLastAmount = max(0.01, round((float) $lastAlloc->amount_allocated + $diff, 2));
                    $lastAlloc->update(['amount_allocated' => $newLastAmount]);
                    if ($lastAlloc->studentFee) {
                        $this->updateStudentFeeStatus($lastAlloc->studentFee->id);
                    }
                }
            }

            // 3. إعادة إسقاط الأثر المالي في الخزينة المركزية بنفس تاريخ القبض الأصلي
            $this->ledger->recordPayment($payment);

            return $payment->fresh(['paymentAllocations.studentFee', 'createdBy', 'editedBy']);
        });
    }

    /**
     * إضافة بند جديد على وصل أو ترسيم (Addition).
     *
     * - بند جديد، مثل مستلزمات بعد ترسيم، ليس تعديلاً.
     * - قيد خزينة جديد بالمبلغ المضاف فقط.
     * - تاريخه هو تاريخ الإضافة الذي يختاره القابض، ولو كان قديماً.
     * - التصنيف مستقل: registration أو supplies أو monthly_fee.
     * - الترسيم لا يتحول إلى مستلزمات، والعكس لا يحدث.
     */
    public function addPaymentItem(Payment $payment, array $data, ?int $userId = null): array
    {
        if ($payment->isCancelled()) {
            throw new \InvalidArgumentException('لا يمكن إضافة بند لوصل ملغى.');
        }

        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ البند المضاف يجب أن يكون أكبر من صفر.');
        }

        $additionDate = ! empty($data['addition_date'])
            ? (string) $data['addition_date']
            : (! empty($data['payment_date']) ? (string) $data['payment_date'] : now()->toDateString());

        $feeTypeId = ! empty($data['fee_type_id']) ? (int) $data['fee_type_id'] : null;
        $feeType = $feeTypeId ? FeeType::find($feeTypeId) : null;
        $description = trim($data['description'] ?? ($feeType?->name_ar ?: 'بند مضاف'));

        return DB::transaction(function () use ($payment, $amount, $additionDate, $feeType, $description, $userId) {
            // 1. إنشاء رسم الطالب للبند المضاف
            $studentFee = StudentFee::create([
                'enrollment_id'       => $payment->enrollment_id,
                'fee_plan_id'         => null,
                'fee_type_id'         => $feeType?->id,
                'club_monthly_fee_id' => null,
                'description'         => $description,
                'amount_due'          => $amount,
                'due_date'            => $additionDate,
                'status'              => 'paid',
            ]);

            // 2. إنشاء دفعة مستقلة للإضافة لضمان قيد خزينة مستقل بتاريخ الإضافة ومبلغها
            $additionPayment = Payment::create([
                'student_id'      => $payment->student_id,
                'enrollment_id'   => $payment->enrollment_id,
                'amount'          => $amount,
                'payment_date'    => $additionDate,
                'method'          => $payment->method,
                'notes'           => 'إضافة بند: ' . $description . ' (تابع للوصل #' . $payment->id . ')',
                'created_by'      => $userId,
                'meta'            => [
                    'parent_payment_id' => $payment->id,
                    'addition_type'     => 'incremental_item',
                    'fee_type_id'       => $feeType?->id,
                ],
            ]);

            PaymentAllocation::create([
                'payment_id'       => $additionPayment->id,
                'student_fee_id'   => $studentFee->id,
                'amount_allocated' => $amount,
            ]);

            $this->updateStudentFeeStatus($studentFee->id);

            // 3. إسقاط قيد الخزينة المستقل للبند المضاف بتاريخ الإضافة بتصنيفه الخاص
            $this->ledger->recordPayment($additionPayment);

            return [
                'parent_payment'   => $payment->fresh(),
                'addition_payment' => $additionPayment->load(['paymentAllocations.studentFee']),
                'student_fee'      => $studentFee,
            ];
        });
    }
}
