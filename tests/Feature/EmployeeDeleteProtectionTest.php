<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
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
            ->assertJson(['message' => 'لا يمكن حذف موظف لديه رواتب أو سلف أو سجلات مالية مرتبطة']);

        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
        $this->assertDatabaseHas('salaries', ['employee_id' => $employee->id]);
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
            ->assertJson(['message' => 'لا يمكن حذف موظف لديه رواتب أو سلف أو سجلات مالية مرتبطة']);

        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
        $this->assertDatabaseHas('employee_advances', ['employee_id' => $employee->id]);
        $this->assertDatabaseHas('employee_advance_repayments', ['employee_id' => $employee->id]);
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
            ->assertJson(['message' => 'لا يمكن حذف موظف لديه رواتب أو سلف أو سجلات مالية مرتبطة']);

        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
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
}
