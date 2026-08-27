<?php

namespace Tests\Feature;

use App\Models\CashTransaction;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\EmployeeDailyHour;
use App\Models\OldEmployeeDebt;
use App\Models\Permission;
use App\Models\Salary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeDeleteProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function makeActiveUserWithPermission(string $permissionName): void
    {
        $user = $this->makeUser('admin');
        $user->update(['is_active' => true]);

        $permission = Permission::firstOrCreate(
            ['name' => $permissionName],
            ['display_name' => $permissionName, 'group' => 'Employees']
        );
        $user->role->permissions()->syncWithoutDetaching($permission->id);

        Sanctum::actingAs($user);
    }

    public function test_employee_with_no_related_records_can_be_deleted(): void
    {
        $this->makeActiveUserWithPermission('manage_employees');

        $employee = Employee::create([
            'first_name' => 'كمال',
            'last_name' => 'الزواغي',
            'is_active' => true,
        ]);

        $response = $this->deleteJson('/api/employees/' . $employee->id);

        $response->assertOk()
            ->assertJson(['message' => 'تم الحذف']);

        $this->assertDatabaseMissing('employees', ['id' => $employee->id]);
    }

    public function test_employee_with_salary_history_cannot_be_deleted(): void
    {
        $this->makeActiveUserWithPermission('manage_employees');
        $year = $this->makeAcademicYear();

        $employee = Employee::create([
            'first_name' => 'علي',
            'last_name' => 'المنصوري',
            'is_active' => true,
        ]);

        Salary::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'gross_amount' => '600.00',
            'advance_deduction' => '0.00',
            'amount' => '600.00',
            'period_from' => '2025-09-01',
            'period_to' => '2025-09-30',
        ]);

        $response = $this->deleteJson('/api/employees/' . $employee->id);

        $response->assertStatus(422)
            ->assertJson(['message' => 'لا يمكن حذف هذا الإطار لأنه مرتبط بسجلات مالية أو رواتب أو سلف.']);

        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
        $this->assertDatabaseHas('salaries', ['employee_id' => $employee->id]);
        $response->assertJsonPath('details.salaries', 1);
    }

    public function test_employee_with_advances_or_repayments_cannot_be_deleted(): void
    {
        $this->makeActiveUserWithPermission('manage_employees');
        $year = $this->makeAcademicYear();

        $employee = Employee::create([
            'first_name' => 'سامية',
            'last_name' => 'البكري',
            'is_active' => true,
        ]);

        $advance = EmployeeAdvance::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'type' => 'loan',
            'amount' => '300.00',
            'settled_amount' => '100.00',
            'advance_date' => '2025-09-05',
            'status' => 'partial',
        ]);

        EmployeeAdvanceRepayment::create([
            'employee_advance_id' => $advance->id,
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'amount' => '100.00',
            'repaid_at' => '2025-09-15',
            'method' => 'cash',
        ]);

        $response = $this->deleteJson('/api/employees/' . $employee->id);

        $response->assertStatus(422)
            ->assertJson(['message' => 'لا يمكن حذف هذا الإطار لأنه مرتبط بسجلات مالية أو رواتب أو سلف.']);

        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
        $this->assertDatabaseHas('employee_advances', ['employee_id' => $employee->id]);
        $this->assertDatabaseHas('employee_advance_repayments', ['employee_id' => $employee->id]);
        $response->assertJsonPath('details.advances', 1);
        $response->assertJsonPath('details.repayments', 1);
    }

    public function test_employee_with_daily_hours_cannot_be_deleted(): void
    {
        $this->makeActiveUserWithPermission('manage_employees');

        $employee = Employee::create([
            'first_name' => 'نور',
            'last_name' => 'الحسني',
            'is_active' => true,
            'staff_type' => 'hourly_teacher',
        ]);

        EmployeeDailyHour::create([
            'employee_id' => $employee->id,
            'work_date' => '2025-09-10',
            'hours' => '4.00',
            'note_type' => 'normal',
        ]);

        $response = $this->deleteJson('/api/employees/' . $employee->id);

        $response->assertStatus(422)
            ->assertJson(['message' => 'لا يمكن حذف هذا الإطار لأنه مرتبط بسجلات مالية أو رواتب أو سلف.']);
        $response->assertJsonPath('details.daily_hours', 1);
        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
    }

    public function test_employee_with_cash_transaction_via_morph_cannot_be_deleted(): void
    {
        $this->makeActiveUserWithPermission('manage_employees');
        $year = $this->makeAcademicYear();

        $employee = Employee::create([
            'first_name' => 'خالد',
            'last_name' => 'العياري',
            'is_active' => true,
        ]);

        $salary = Salary::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'gross_amount' => '800.00',
            'advance_deduction' => '0.00',
            'amount' => '800.00',
            'period_from' => '2025-09-01',
            'period_to' => '2025-09-30',
            'paid_at' => '2025-09-30',
        ]);

        CashTransaction::create([
            'transaction_date' => '2025-09-30',
            'direction' => CashTransaction::DIRECTION_OUT,
            'category' => CashTransaction::CATEGORY_SALARY,
            'amount' => '800.00',
            'source_type' => $salary->getMorphClass(),
            'source_id' => $salary->getKey(),
            'academic_year_id' => $year->id,
        ]);

        $response = $this->deleteJson('/api/employees/' . $employee->id);

        $response->assertStatus(422)
            ->assertJson(['message' => 'لا يمكن حذف هذا الإطار لأنه مرتبط بسجلات مالية أو رواتب أو سلف.']);
        $response->assertJsonPath('details.cash_transactions', 1);
        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
    }

    public function test_employee_with_advance_repayment_morph_cash_blocked(): void
    {
        $this->makeActiveUserWithPermission('manage_employees');
        $year = $this->makeAcademicYear();

        $employee = Employee::create([
            'first_name' => 'ريم',
            'last_name' => 'الشابي',
            'is_active' => true,
        ]);

        $advance = EmployeeAdvance::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'type' => 'loan',
            'amount' => '500.00',
            'advance_date' => '2025-09-05',
            'status' => 'partial',
        ]);

        $repayment = EmployeeAdvanceRepayment::create([
            'employee_advance_id' => $advance->id,
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'amount' => '100.00',
            'repaid_at' => '2025-09-15',
            'method' => 'cash',
        ]);

        CashTransaction::create([
            'transaction_date' => '2025-09-15',
            'direction' => CashTransaction::DIRECTION_IN,
            'category' => CashTransaction::CATEGORY_ADVANCE_REPAYMENT,
            'amount' => '100.00',
            'source_type' => $repayment->getMorphClass(),
            'source_id' => $repayment->getKey(),
            'academic_year_id' => $year->id,
        ]);

        $response = $this->deleteJson('/api/employees/' . $employee->id);
        $response->assertStatus(422);
        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
    }

    public function test_delete_details_counts_are_accurate(): void
    {
        $this->makeActiveUserWithPermission('manage_employees');
        $year = $this->makeAcademicYear();

        $employee = Employee::create([
            'first_name' => 'تفاصيل',
            'last_name' => 'الاختبار',
            'is_active' => true,
            'staff_type' => 'monthly_teacher',
        ]);

        Salary::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'gross_amount' => '600.00',
            'advance_deduction' => '0.00',
            'amount' => '600.00',
            'period_from' => '2025-09-01',
            'period_to' => '2025-09-30',
        ]);

        EmployeeDailyHour::create([
            'employee_id' => $employee->id,
            'work_date' => '2025-09-11',
            'hours' => '3.00',
            'note_type' => 'normal',
        ]);

        $response = $this->deleteJson('/api/employees/' . $employee->id);
        $response->assertStatus(422);
        $response->assertJsonPath('details.salaries', 1);
        $response->assertJsonPath('details.daily_hours', 1);
        $response->assertJsonPath('details.advances', 0);
        $response->assertJsonPath('details.repayments', 0);
        // message exact
        $response->assertJson(['message' => 'لا يمكن حذف هذا الإطار لأنه مرتبط بسجلات مالية أو رواتب أو سلف.']);
    }

    public function test_no_records_changed_when_delete_blocked(): void
    {
        $this->makeActiveUserWithPermission('manage_employees');
        $year = $this->makeAcademicYear();

        $employee = Employee::create([
            'first_name' => 'ثابت',
            'last_name' => 'السجل',
            'is_active' => true,
        ]);

        $salary = Salary::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'gross_amount' => '500.00',
            'advance_deduction' => '0.00',
            'amount' => '500.00',
            'period_from' => '2025-09-01',
            'period_to' => '2025-09-30',
        ]);

        $countBeforeSalaries = Salary::count();
        $countBeforeAdvances = EmployeeAdvance::count();

        $this->deleteJson('/api/employees/' . $employee->id)->assertStatus(422);

        $this->assertEquals($countBeforeSalaries, Salary::count());
        $this->assertEquals($countBeforeAdvances, EmployeeAdvance::count());
        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
        $this->assertDatabaseHas('salaries', ['id' => $salary->id]);
    }

    public function test_employee_with_related_financial_ledger_records_cannot_be_deleted(): void
    {
        $this->makeActiveUserWithPermission('manage_employees');
        $year = $this->makeAcademicYear();

        $employee = Employee::create([
            'first_name' => 'خالد',
            'last_name' => 'العياري',
            'is_active' => true,
        ]);

        EmployeeAdvance::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'type' => 'advance',
            'amount' => '150.00',
            'advance_date' => '2025-10-01',
            'status' => 'pending',
        ]);

        $response = $this->deleteJson('/api/employees/' . $employee->id);

        $response->assertStatus(422)
            ->assertJson(['message' => 'لا يمكن حذف هذا الإطار لأنه مرتبط بسجلات مالية أو رواتب أو سلف.']);

        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
    }

    public function test_employee_with_opening_debts_cannot_be_deleted(): void
    {
        $this->makeActiveUserWithPermission('manage_employees');
        $year = $this->makeAcademicYear();

        $employee = Employee::create([
            'first_name' => 'صابر',
            'last_name' => 'الحمروني',
            'is_active' => true,
        ]);

        OldEmployeeDebt::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'debt',
            'description' => 'دين قديم سابق',
            'original_amount' => '250.00',
            'status' => 'pending',
        ]);

        $response = $this->deleteJson('/api/employees/' . $employee->id);

        $response->assertStatus(422)
            ->assertJson(['message' => 'لا يمكن حذف هذا الإطار لأنه مرتبط بسجلات مالية أو رواتب أو سلف.']);

        $response->assertJsonPath('details.opening_debts', 1);
        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
    }
}
