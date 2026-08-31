<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Club;
use App\Models\ClubMonthlyFee;
use App\Models\ClubSubscription;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\Enrollment;
use App\Models\Level;
use App\Models\ManualStudentDebt;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanSessionTestDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_session_test_data_wipes_test_students_and_mustapha_debts_while_preserving_student_old_debts(): void
    {
        $year = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);

        $level = Level::create(['name' => 'السنة الأولى', 'code' => '1', 'order_index' => 1]);
        $section = Section::create(['name' => 'أ', 'code' => '1A', 'level_id' => $level->id]);

        // 1. Existing real student from before 2026-08-30 with old debt
        $oldStudent = new Student([
            'student_code' => 'PRV-OLD-001',
            'first_name' => 'أحمد',
            'last_name' => 'القديم',
        ]);
        $oldStudent->timestamps = false;
        $oldStudent->created_at = '2026-08-15 10:00:00';
        $oldStudent->updated_at = '2026-08-15 10:00:00';
        $oldStudent->save();

        $oldEnrollment = Enrollment::create([
            'student_id' => $oldStudent->id,
            'academic_year_id' => $year->id,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => '2026-08-15',
            'status' => 'active',
            'created_at' => '2026-08-15 10:00:00',
            'updated_at' => '2026-08-15 10:00:00',
        ]);

        $oldDebt = ManualStudentDebt::create([
            'student_id' => $oldStudent->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2025-2026',
            'debt_type' => 'tuition',
            'description' => 'دين متخلد سابق',
            'original_amount' => 150.00,
            'status' => ManualStudentDebt::STATUS_PENDING,
            'notes' => 'دين متخلد سابق',
        ]);

        $oldDebtFee = StudentFee::create([
            'enrollment_id' => $oldEnrollment->id,
            'amount_due' => 150.00,
            'direct_paid_amount' => 0.00,
            'due_date' => '2026-09-01',
            'status' => 'unpaid',
            'description' => 'دَين قديم: دين متخلد سابق',
        ]);

        $oldDebt->update(['source_student_fee_id' => $oldDebtFee->id]);

        // 2. Test student created today
        $testStudent = Student::create([
            'student_code' => 'PRV-TEST-999',
            'first_name' => 'تلميذ',
            'last_name' => 'تجريبي',
            'created_at' => '2026-08-31 10:00:00',
            'updated_at' => '2026-08-31 10:00:00',
        ]);

        $testEnrollment = Enrollment::create([
            'student_id' => $testStudent->id,
            'academic_year_id' => $year->id,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => '2026-08-31',
            'status' => 'active',
            'created_at' => '2026-08-31 10:00:00',
            'updated_at' => '2026-08-31 10:00:00',
        ]);

        $testFee = StudentFee::create([
            'enrollment_id' => $testEnrollment->id,
            'amount_due' => 120.00,
            'direct_paid_amount' => 120.00,
            'due_date' => '2026-09-01',
            'status' => 'paid',
            'created_at' => '2026-08-31 10:00:00',
        ]);

        $testPayment = Payment::create([
            'student_id' => $testStudent->id,
            'enrollment_id' => $testEnrollment->id,
            'academic_year_id' => $year->id,
            'amount' => 120.00,
            'payment_date' => '2026-08-31',
            'method' => 'cash',
            'created_at' => '2026-08-31 10:00:00',
        ]);

        PaymentAllocation::create([
            'payment_id' => $testPayment->id,
            'student_fee_id' => $testFee->id,
            'amount_allocated' => 120.00,
        ]);

        CashTransaction::create([
            'source_type' => (new Payment)->getMorphClass(),
            'source_id' => $testPayment->id,
            'direction' => 'in',
            'category' => 'tuition_fee',
            'amount' => 120.00,
            'transaction_date' => '2026-08-31',
            'created_at' => '2026-08-31 10:00:00',
        ]);

        // 3. Employee Mustapha Abdouli with an advance
        $mustapha = Employee::create([
            'first_name' => 'مصطفى',
            'last_name' => 'العبدولي',
            'role' => 'عامل',
        ]);

        $adv = EmployeeAdvance::create([
            'employee_id' => $mustapha->id,
            'academic_year_id' => $year->id,
            'amount' => 200.00,
            'paid_back' => 0.00,
            'advance_date' => '2026-08-31',
            'status' => 'pending',
        ]);

        CashTransaction::create([
            'source_type' => (new EmployeeAdvance)->getMorphClass(),
            'source_id' => $adv->id,
            'direction' => 'out',
            'category' => 'advance',
            'amount' => 200.00,
            'transaction_date' => '2026-08-31',
            'created_at' => '2026-08-31 10:00:00',
        ]);

        // Execute app:clean-test-data --force
        $this->artisan('app:clean-test-data', ['--force' => true])
            ->assertSuccessful();

        // 4. Assertions:
        // Old student & debt MUST exist and be restored
        $this->assertDatabaseHas('students', ['id' => $oldStudent->id]);
        $this->assertDatabaseHas('manual_student_debts', [
            'id' => $oldDebt->id,
            'original_amount' => 150.00,
            'status' => ManualStudentDebt::STATUS_PENDING,
        ]);

        // Test student & test payment & test fee MUST be wiped
        $this->assertDatabaseMissing('students', ['id' => $testStudent->id]);
        $this->assertDatabaseMissing('enrollments', ['id' => $testEnrollment->id]);
        $this->assertDatabaseMissing('payments', ['id' => $testPayment->id]);
        $this->assertDatabaseMissing('student_fees', ['id' => $testFee->id]);

        // Mustapha's advance MUST be wiped, but Mustapha's employee record preserved
        $this->assertDatabaseHas('employees', ['id' => $mustapha->id]);
        $this->assertDatabaseMissing('employee_advances', ['id' => $adv->id]);
        $this->assertDatabaseMissing('cash_transactions', [
            'source_type' => (new EmployeeAdvance)->getMorphClass(),
            'source_id' => $adv->id,
        ]);
        $this->assertDatabaseMissing('cash_transactions', [
            'source_type' => (new Payment)->getMorphClass(),
            'source_id' => $testPayment->id,
        ]);
    }
}
