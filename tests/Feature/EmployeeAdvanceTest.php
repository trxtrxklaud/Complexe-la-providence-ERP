<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

use Laravel\Sanctum\Sanctum;

class EmployeeAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Employee $employee;
    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('admin');
        $this->admin->update(['is_active' => true]);
        Sanctum::actingAs($this->admin);

        $this->year = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);

        $this->employee = Employee::create([
            'first_name' => 'مصطفى',
            'last_name' => 'عبدولي',
            'job_title' => 'معلم',
            'is_active' => true,
        ]);
    }

    public function test_can_create_employee_loan_with_installments_and_due_date(): void
    {
        $payload = [
            'employee_id' => $this->employee->id,
            'academic_year_id' => $this->year->id,
            'type' => 'loan',
            'amount' => 600.00,
            'installment_count' => 3,
            'repayment_method' => 'salary_deduction',
            'due_date' => '2026-12-31',
            'purpose' => 'سلفة شخصية لاختبار الأقساط',
            'advance_date' => '2026-09-20',
        ];

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/employee-advances', $payload);

        $response->assertStatus(201);

        $this->assertDatabaseHas('employee_advances', [
            'employee_id' => $this->employee->id,
            'type' => 'loan',
            'amount' => 600.00,
            'installment_count' => 3,
            'repayment_method' => 'salary_deduction',
            'status' => EmployeeAdvance::STATUS_PENDING,
        ]);

        $advance = EmployeeAdvance::latest('id')->first();
        $this->assertEquals('2026-12-31', $advance->due_date->format('Y-m-d'));
        $this->assertEquals('سلفة شخصية لاختبار الأقساط', $advance->purpose);

        // Verify ledger cash transaction was posted
        $this->assertDatabaseHas('cash_transactions', [
            'category' => CashTransaction::CATEGORY_EMPLOYEE_ADVANCE,
            'direction' => CashTransaction::DIRECTION_OUT,
            'amount' => 600.00,
        ]);
    }

    public function test_can_settle_loan_installment(): void
    {
        $advance = EmployeeAdvance::create([
            'employee_id' => $this->employee->id,
            'academic_year_id' => $this->year->id,
            'type' => 'loan',
            'amount' => 600.00,
            'settled_amount' => 0.00,
            'installment_count' => 3,
            'repayment_method' => 'cash',
            'advance_date' => '2026-09-20',
            'status' => EmployeeAdvance::STATUS_PENDING,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/employee-advances/{$advance->id}/settle", [
                'amount' => 200.00,
                'method' => 'cash',
                'repaid_at' => '2026-10-01',
                'notes' => 'خلاص القسط الأول',
            ]);

        $response->assertStatus(201);

        $advance->refresh();
        $this->assertEquals(200.00, (float) $advance->settled_amount);
        $this->assertEquals(EmployeeAdvance::STATUS_PARTIAL, $advance->status);

        // Verify repayment record created
        $this->assertDatabaseHas('employee_advance_repayments', [
            'employee_advance_id' => $advance->id,
            'amount' => 200.00,
            'method' => 'cash',
        ]);

        // Verify cash in ledger transaction
        $this->assertDatabaseHas('cash_transactions', [
            'category' => CashTransaction::CATEGORY_ADVANCE_REPAYMENT,
            'direction' => CashTransaction::DIRECTION_IN,
            'amount' => 200.00,
        ]);
    }
}
