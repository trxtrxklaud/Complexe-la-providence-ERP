<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\StudentFee;
use App\Models\PaymentAllocation;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    /**
     * Record a payment and allocate it to specific student fees.
     */
    public function recordPayment(array $data): Payment
    {
        return DB::transaction(function () use ($data) {
            $payment = Payment::create([
                'student_id'    => $data['student_id'],
                'enrollment_id' => $data['enrollment_id'] ?? null,
                'amount'        => $data['amount'],
                'payment_date'  => $data['payment_date'],
                'method'        => $data['method'],
                'reference'     => $data['reference'] ?? null,
                'notes'         => $data['notes'] ?? null,
                'created_by'    => $data['created_by'],
            ]);

            // If specific allocations are provided
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
    }

    private function updateStudentFeeStatus(int $studentFeeId): void
    {
        $fee = StudentFee::find($studentFeeId);
        if (!$fee) return;

        $allocated = $fee->paymentAllocations()->sum('amount_allocated');

        if ($allocated >= $fee->amount_due) {
            $fee->update(['status' => 'paid']);
        } elseif ($allocated > 0) {
            $fee->update(['status' => 'partial']);
        }
    }

    public function getStudentBalance(int $studentId): float
    {
        return StudentFee::whereHas('enrollment', fn($q) => $q->where('student_id', $studentId))
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->get()
            ->sum(function ($fee) {
                $allocated = $fee->paymentAllocations()->sum('amount_allocated');
                return $fee->amount_due - $allocated;
            });
    }
}
