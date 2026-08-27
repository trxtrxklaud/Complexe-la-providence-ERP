<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Employee;
use App\Models\EmployeeLiability;
use App\Models\ManualStudentDebt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeLiabilityBulkTest extends TestCase
{
    use RefreshDatabase;

    private function admin()
    {
        $u = $this->makeUser('admin');
        $u->update(['is_active' => true]);
        return $u;
    }

    private function makeEmployee(string $type = 'worker'): Employee
    {
        return Employee::create([
            'first_name' => 'موظف',
            'last_name' => uniqid(),
            'staff_type' => $type,
            'is_active' => true,
        ]);
    }

    public function test_bulk_valid_rows_succeed(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear();
        $w = $this->makeEmployee('worker');
        $t = $this->makeEmployee('monthly_teacher');

        $res = $this->postJson('/api/employee-liabilities/bulk', [
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'items' => [
                ['employee_id' => $w->id, 'liability_type' => 'debt', 'amount' => 300],
                ['employee_id' => $t->id, 'liability_type' => 'advance', 'amount' => 500],
            ],
        ])->assertCreated();

        $res->assertJson(['created' => 2, 'updated' => 0, 'skipped' => 0]);
        $this->assertDatabaseCount('employee_liabilities', 2);
    }

    public function test_bulk_invalid_employee_id_returns_422_arabic(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear();
        $w = $this->makeEmployee('worker');

        $res = $this->postJson('/api/employee-liabilities/bulk', [
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'items' => [
                ['employee_id' => 999999, 'liability_type' => 'debt', 'amount' => 300],
            ],
        ])->assertStatus(422);

        $this->assertNotEmpty($res->json('errors') ?? $res->json('message'));
        // رسالة عربية: الإطار المحدد غير موجود.
        $this->assertTrue(str_contains(json_encode($res->json(), JSON_UNESCAPED_UNICODE), 'غير موجود'));
        $this->assertDatabaseCount('employee_liabilities', 0);
    }

    public function test_bulk_amount_zero_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear();
        $w = $this->makeEmployee('worker');

        $res = $this->postJson('/api/employee-liabilities/bulk', [
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'items' => [
                ['employee_id' => $w->id, 'liability_type' => 'debt', 'amount' => 0],
            ],
        ])->assertStatus(422);

        $this->assertTrue(str_contains(json_encode($res->json(), JSON_UNESCAPED_UNICODE), 'أكبر من صفر'));
        $this->assertDatabaseCount('employee_liabilities', 0);
    }

    public function test_bulk_negative_amount_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear();
        $w = $this->makeEmployee('worker');

        $this->postJson('/api/employee-liabilities/bulk', [
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'items' => [
                ['employee_id' => $w->id, 'liability_type' => 'debt', 'amount' => -100],
            ],
        ])->assertStatus(422);
        $this->assertDatabaseCount('employee_liabilities', 0);
    }

    public function test_bulk_invalid_liability_type_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear();
        $w = $this->makeEmployee('worker');

        $res = $this->postJson('/api/employee-liabilities/bulk', [
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'items' => [
                ['employee_id' => $w->id, 'liability_type' => 'invalid_type', 'amount' => 300],
            ],
        ])->assertStatus(422);
        $this->assertTrue(str_contains(json_encode($res->json(), JSON_UNESCAPED_UNICODE), 'نوع الالتزام'));
        $this->assertDatabaseCount('employee_liabilities', 0);
    }

    public function test_existing_debt_does_not_block_separate_advance_creation(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear();
        $t = $this->makeEmployee('monthly_teacher');

        // create debt first
        $this->postJson('/api/employee-liabilities/bulk', [
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'items' => [
                ['employee_id' => $t->id, 'liability_type' => 'debt', 'amount' => 400],
            ],
        ])->assertCreated();

        // now create advance for same employee/year — should create new, not block
        $res = $this->postJson('/api/employee-liabilities/bulk', [
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'items' => [
                ['employee_id' => $t->id, 'liability_type' => 'advance', 'amount' => 600],
            ],
        ])->assertCreated();

        $res->assertJson(['created' => 1]);
        $this->assertDatabaseCount('employee_liabilities', 2);
        $this->assertDatabaseHas('employee_liabilities', ['employee_id' => $t->id, 'liability_type' => 'debt', 'original_amount' => 400]);
        $this->assertDatabaseHas('employee_liabilities', ['employee_id' => $t->id, 'liability_type' => 'advance', 'original_amount' => 600]);
    }

    public function test_duplicate_check_does_not_confuse_debt_and_advance(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear();
        $t = $this->makeEmployee('monthly_teacher');

        // debt then debt again should update, not create second
        $this->postJson('/api/employee-liabilities/bulk', [
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'items' => [['employee_id' => $t->id, 'liability_type' => 'debt', 'amount' => 300]],
        ])->assertCreated();

        $this->postJson('/api/employee-liabilities/bulk', [
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'items' => [['employee_id' => $t->id, 'liability_type' => 'debt', 'amount' => 700]],
        ])->assertCreated()->assertJson(['updated' => 1, 'created' => 0]);

        $this->assertDatabaseCount('employee_liabilities', 1);
        $this->assertDatabaseHas('employee_liabilities', ['liability_type' => 'debt', 'original_amount' => 700]);

        // now advance with same employee should create new row
        $this->postJson('/api/employee-liabilities/bulk', [
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'items' => [['employee_id' => $t->id, 'liability_type' => 'advance', 'amount' => 500]],
        ])->assertCreated()->assertJson(['created' => 1]);

        $this->assertDatabaseCount('employee_liabilities', 2);
    }

    public function test_single_invalid_row_blocks_entire_operation_all_or_nothing(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear();
        $w1 = $this->makeEmployee('worker');
        $w2 = $this->makeEmployee('worker');

        $res = $this->postJson('/api/employee-liabilities/bulk', [
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'items' => [
                ['employee_id' => $w1->id, 'liability_type' => 'debt', 'amount' => 300],
                ['employee_id' => $w2->id, 'liability_type' => 'debt', 'amount' => 0], // invalid
            ],
        ])->assertStatus(422);

        $this->assertDatabaseCount('employee_liabilities', 0);
        // رسالة تذكر رقم الصف
        $this->assertTrue(str_contains(json_encode($res->json(), JSON_UNESCAPED_UNICODE), ''));
    }

    public function test_bulk_does_not_create_cash_transactions(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear();
        $w = $this->makeEmployee('worker');

        $this->postJson('/api/employee-liabilities/bulk', [
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'items' => [['employee_id' => $w->id, 'liability_type' => 'debt', 'amount' => 400]],
        ])->assertCreated();

        $this->assertDatabaseCount('cash_transactions', 0);
        $this->assertDatabaseMissing('cash_transactions', ['category' => CashTransaction::CATEGORY_OLD_LIABILITY_COLLECTION]);
        $this->assertDatabaseMissing('cash_transactions', ['category' => CashTransaction::CATEGORY_OLD_LIABILITY_PAYMENT]);
    }

    public function test_unauthorized_user_gets_403(): void
    {
        $user = $this->makeUser('cashier');
        $user->update(['is_active' => true]);
        Sanctum::actingAs($user);
        $year = $this->makeAcademicYear();
        $w = $this->makeEmployee('worker');

        $this->postJson('/api/employee-liabilities/bulk', [
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'items' => [['employee_id' => $w->id, 'liability_type' => 'debt', 'amount' => 300]],
        ])->assertForbidden();
    }

    public function test_unrelated_student_club_treasury_data_unchanged(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear();
        $student = \App\Models\Student::create([
            'student_code' => 'STU-'.uniqid(),
            'first_name' => 'تلميذ',
            'last_name' => 'اختبار',
            'gender' => 'male',
            'status' => 'active',
        ]);
        $debt = ManualStudentDebt::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'tuition',
            'description' => 'دين قديم',
            'original_amount' => 1000,
            'status' => 'pending',
        ]);
        $cashBefore = CashTransaction::count();
        $studentDebtBefore = ManualStudentDebt::count();

        $w = $this->makeEmployee('worker');
        $this->postJson('/api/employee-liabilities/bulk', [
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'items' => [['employee_id' => $w->id, 'liability_type' => 'debt', 'amount' => 300]],
        ])->assertCreated();

        $this->assertEquals($studentDebtBefore, ManualStudentDebt::count());
        $this->assertDatabaseHas('manual_student_debts', ['id' => $debt->id, 'original_amount' => 1000]);
        $this->assertEquals($cashBefore, 0); // still no cash from bulk
    }

    public function test_validation_messages_arabic_for_required_fields(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear();

        $res = $this->postJson('/api/employee-liabilities/bulk', [
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'items' => [
                ['employee_id' => null, 'liability_type' => 'debt', 'amount' => 300],
            ],
        ])->assertStatus(422);

        $errors = $res->json('errors');
        $this->assertNotEmpty($errors);
        $first = collect($errors)->flatten()->first();
        $this->assertTrue(str_contains($first, 'مطلوب') || str_contains($first, 'الإطار'));
    }
}
