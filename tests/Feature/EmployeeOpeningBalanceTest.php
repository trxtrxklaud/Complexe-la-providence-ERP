<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Employee;
use App\Models\EmployeeLiability;
use App\Models\Salary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeOpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $user = $this->makeUser('admin');
        $user->update(['is_active' => true]);
        Sanctum::actingAs($user);

        $this->year = $this->makeAcademicYear('2026-2027');
    }

    public function test_teachers_and_animator_accept_debt_and_unpaid_advance(): void
    {
        foreach (['club_animator', 'hourly_teacher', 'monthly_teacher'] as $staffType) {
            $employee = $this->employee($staffType);

            foreach (['debt', 'advance'] as $liabilityType) {
                $this->postJson('/api/employee-liabilities', $this->payload($employee, $liabilityType))
                    ->assertCreated()
                    ->assertJsonPath('employee_id', $employee->id)
                    ->assertJsonPath('liability_type', $liabilityType);
            }
        }

        $this->assertDatabaseCount('employee_liabilities', 6);
    }

    public function test_worker_accepts_debt_only(): void
    {
        $employee = $this->employee('worker');

        $this->postJson('/api/employee-liabilities', $this->payload($employee, 'debt'))
            ->assertCreated();

        $this->postJson('/api/employee-liabilities', $this->payload($employee, 'advance'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('liability_type');

        $this->assertDatabaseCount('employee_liabilities', 1);
    }

    public function test_manager_and_supervisor_fall_back_to_debt_only(): void
    {
        foreach (['manager', 'supervisor'] as $staffType) {
            $employee = $this->employee($staffType);

            $this->postJson('/api/employee-liabilities', $this->payload($employee, 'debt'))
                ->assertCreated();

            $this->postJson('/api/employee-liabilities', $this->payload($employee, 'advance'))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('liability_type');
        }

        $this->assertDatabaseCount('employee_liabilities', 2);
    }

    public function test_legacy_liability_types_are_rejected_for_supported_employees(): void
    {
        $employee = $this->employee('monthly_teacher');

        foreach (['bonus', 'other', 'salary'] as $liabilityType) {
            $this->postJson('/api/employee-liabilities', $this->payload($employee, $liabilityType))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('liability_type');
        }

        $this->assertDatabaseCount('employee_liabilities', 0);
    }

    public function test_unknown_employee_type_falls_back_to_debt_only(): void
    {
        $employee = $this->employee('unsupported');

        $this->postJson('/api/employee-liabilities', $this->payload($employee, 'debt'))
            ->assertCreated();

        $this->postJson('/api/employee-liabilities', $this->payload($employee, 'advance'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('liability_type');

        $this->assertDatabaseCount('employee_liabilities', 1);
    }

    public function test_liability_payments_use_liability_as_ledger_source_and_accumulate(): void
    {
        $employee = $this->employee('monthly_teacher');
        $liability = $this->createLiability($employee, 500);

        $first = $this->postJson('/api/employee-liabilities/'.$liability->id.'/pay', [
            'amount' => 200,
            'paid_at' => '2026-09-10',
            'notes' => null,
        ])->assertCreated();

        $this->assertDatabaseHas('cash_transactions', [
            'source_type' => EmployeeLiability::class,
            'source_id' => $liability->id,
            'category' => CashTransaction::CATEGORY_OLD_LIABILITY_PAYMENT,
            'amount' => 200,
            'cancelled_at' => null,
        ]);
        $this->assertDatabaseMissing('cash_transactions', [
            'source_type' => Salary::class,
            'source_id' => $first->json('salary.id'),
            'category' => CashTransaction::CATEGORY_OLD_LIABILITY_PAYMENT,
        ]);
        $this->assertSame(200.0, $liability->fresh()->paid());
        $this->assertSame(EmployeeLiability::STATUS_PARTIAL, $liability->fresh()->status);
        $this->assertSame('2026-09-10', CashTransaction::firstOrFail()->transaction_date->toDateString());

        $second = $this->postJson('/api/employee-liabilities/'.$liability->id.'/pay', [
            'amount' => 300,
            'paid_at' => '2026-10-10',
            'notes' => null,
        ])->assertCreated();

        $this->assertDatabaseCount('cash_transactions', 1);
        $this->assertDatabaseHas('cash_transactions', [
            'source_type' => EmployeeLiability::class,
            'source_id' => $liability->id,
            'category' => CashTransaction::CATEGORY_OLD_LIABILITY_PAYMENT,
            'amount' => 500,
            'cancelled_at' => null,
        ]);
        $this->assertSame(500.0, $liability->fresh()->paid());
        $this->assertSame(EmployeeLiability::STATUS_PAID, $liability->fresh()->status);

        $this->postJson('/api/salaries/'.$second->json('salary.id').'/cancel', [
            'reason' => 'إلغاء الدفعة الثانية',
        ])->assertOk();

        $this->assertSame(200.0, $liability->fresh()->paid());
        $this->assertSame(EmployeeLiability::STATUS_PARTIAL, $liability->fresh()->status);

        $this->postJson('/api/salaries/'.$first->json('salary.id').'/cancel', [
            'reason' => 'إلغاء الدفعة الأولى',
        ])->assertOk();

        $this->assertSame(0.0, $liability->fresh()->paid());
        $this->assertSame(EmployeeLiability::STATUS_PENDING, $liability->fresh()->status);
        $this->assertNotNull(CashTransaction::firstOrFail()->cancelled_at);

        $this->postJson('/api/employee-liabilities/'.$liability->id.'/cancel', [
            'reason' => 'إدخال الدين بالخطأ',
        ])->assertOk();
    }

    private function employee(string $staffType): Employee
    {
        return Employee::create([
            'first_name' => 'سناء',
            'last_name' => $staffType,
            'job_title' => 'إطار تربوي',
            'staff_type' => $staffType,
            'salary_type' => $staffType === 'hourly_teacher' ? 'hourly' : 'monthly',
            'is_active' => true,
        ]);
    }

    private function payload(Employee $employee, string $liabilityType): array
    {
        return [
            'employee_id' => $employee->id,
            'academic_year_id' => $this->year->id,
            'original_year_label' => '2025/2026',
            'liability_type' => $liabilityType,
            'description' => 'رصيد افتتاحي للاختبار',
            'original_amount' => 500,
        ];
    }

    private function createLiability(Employee $employee, float $amount): EmployeeLiability
    {
        $response = $this->postJson('/api/employee-liabilities', $this->payload($employee, 'debt') + [
            'original_amount' => $amount,
        ])->assertCreated();

        return EmployeeLiability::findOrFail($response->json('id'));
    }
}
