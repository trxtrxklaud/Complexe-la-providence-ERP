<?php

namespace Tests\Feature;

use App\Models\PaymentAllocation;
use App\Models\StudentFee;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PaymentService::class);
    }

    private function makeFee(int $enrollmentId, float $amount = 300, string $desc = 'قسط'): StudentFee
    {
        return StudentFee::create([
            'enrollment_id' => $enrollmentId,
            'fee_plan_id'   => null,
            'description'   => $desc,
            'amount_due'    => $amount,
            'due_date'      => '2025-09-05',
            'status'        => 'pending',
        ]);
    }

    public function test_partial_payment_marks_fee_as_partial(): void
    {
        $user = $this->makeUser();
        $enrollment = $this->makeEnrollment();
        $fee = $this->makeFee($enrollment->id, 300);

        $this->service->recordPayment([
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'amount'        => 100,
            'payment_date'  => '2025-09-05',
            'method'        => 'cash',
            'allocations'   => [
                ['student_fee_id' => $fee->id, 'amount' => 100],
            ],
        ], $user->id);

        $this->assertSame('partial', $fee->fresh()->status);
    }

    public function test_full_payment_marks_fee_as_paid(): void
    {
        $user = $this->makeUser();
        $enrollment = $this->makeEnrollment();
        $fee = $this->makeFee($enrollment->id, 300);

        $this->service->recordPayment([
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'amount'        => 300,
            'payment_date'  => '2025-09-05',
            'method'        => 'cash',
            'allocations'   => [
                ['student_fee_id' => $fee->id, 'amount' => 300],
            ],
        ], $user->id);

        $this->assertSame('paid', $fee->fresh()->status);
    }

    public function test_rejects_allocation_exceeding_payment_amount(): void
    {
        $user = $this->makeUser();
        $enrollment = $this->makeEnrollment();
        $fee = $this->makeFee($enrollment->id, 300);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->recordPayment([
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'amount'        => 100,
            'payment_date'  => '2025-09-05',
            'method'        => 'cash',
            'allocations'   => [
                ['student_fee_id' => $fee->id, 'amount' => 200],
            ],
        ], $user->id);
    }

    public function test_rejects_over_allocating_a_fee(): void
    {
        $user = $this->makeUser();
        $enrollment = $this->makeEnrollment();
        $fee = $this->makeFee($enrollment->id, 300);

        PaymentAllocation::unguarded(function () {});

        $this->service->recordPayment([
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'amount'        => 300,
            'payment_date'  => '2025-09-05',
            'method'        => 'cash',
            'allocations'   => [['student_fee_id' => $fee->id, 'amount' => 300]],
        ], $user->id);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->recordPayment([
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'amount'        => 50,
            'payment_date'  => '2025-09-06',
            'method'        => 'cash',
            'allocations'   => [['student_fee_id' => $fee->id, 'amount' => 50]],
        ], $user->id);
    }

    public function test_student_balance_counts_only_unpaid_remainder(): void
    {
        $user = $this->makeUser();
        $enrollment = $this->makeEnrollment();
        $fee = $this->makeFee($enrollment->id, 300);

        $this->assertSame(300.0, $this->service->getStudentBalance($enrollment->student_id));

        $this->service->recordPayment([
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'amount'        => 120,
            'payment_date'  => '2025-09-05',
            'method'        => 'cash',
            'allocations'   => [['student_fee_id' => $fee->id, 'amount' => 120]],
        ], $user->id);

        $this->assertSame(180.0, $this->service->getStudentBalance($enrollment->student_id));
    }

    public function test_recalculate_resets_status_when_allocations_removed(): void
    {
        $user = $this->makeUser();
        $enrollment = $this->makeEnrollment();
        $fee = $this->makeFee($enrollment->id, 300);

        $this->service->recordPayment([
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'amount'        => 300,
            'payment_date'  => '2025-09-05',
            'method'        => 'cash',
            'allocations'   => [['student_fee_id' => $fee->id, 'amount' => 300]],
        ], $user->id);

        $this->assertSame('paid', $fee->fresh()->status);

        PaymentAllocation::where('student_fee_id', $fee->id)->delete();
        $this->service->recalculateStudentFeeStatus($fee->id);

        $this->assertSame('pending', $fee->fresh()->status);
    }
}
