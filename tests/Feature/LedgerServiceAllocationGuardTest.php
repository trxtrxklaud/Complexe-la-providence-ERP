<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Level;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use App\Services\LedgerService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerServiceAllocationGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_payment_throws_domain_exception_when_allocations_exceed_payment_amount(): void
    {
        $year = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);
        $level = Level::create(['name' => 'L1', 'code' => 'L1']);
        $section = Section::create(['level_id' => $level->id, 'name' => 'A', 'code' => 'L1-A', 'capacity' => 30]);
        $student = Student::create(['first_name' => 'Test', 'last_name' => 'Student']);
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => '2026-09-01',
            'status' => 'active',
        ]);
        $feeType = FeeType::create(['name_ar' => 'معلوم', 'name_fr' => 'Frais', 'code' => 'TUITION', 'price' => 190.00]);
        $fee = StudentFee::create([
            'enrollment_id' => $enrollment->id,
            'fee_type_id' => $feeType->id,
            'amount_due' => 200.00,
            'due_date' => '2026-09-01',
            'status' => 'pending',
        ]);

        $payment = Payment::create([
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'amount' => 100.00, // Payment is 100
            'payment_date' => '2026-09-01',
            'method' => 'cash',
        ]);

        // Allocation is 150 (exceeds payment amount of 100)
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'student_fee_id' => $fee->id,
            'amount_allocated' => 150.00,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('مجموع التوزيعات');

        $ledgerService = app(LedgerService::class);
        $ledgerService->recordPayment($payment);
    }
}
