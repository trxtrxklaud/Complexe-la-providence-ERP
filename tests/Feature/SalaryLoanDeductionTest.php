<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\Permission;
use App\Models\Salary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * خصم أقساط السلف من الراتب.
 *
 * الخلل الذي تحرسه هذه الاختبارات: كانت طريقة الردّ salary_deduction
 * موجودة في النموذج بلا أي مسار يربطها براتب حقيقي، فكان يُطفأ
 * دَين الإطار دون أن ينقُص راتب ودون أن يدخل الصندوق مليم.
 */
class SalaryLoanDeductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_loan_installment_is_deducted_from_salary_and_linked_to_it(): void
    {
        Sanctum::actingAs($this->makePayrollClerk());

        $year     = $this->makeAcademicYear();
        $employee = $this->makeEmployee();
        $loan     = $this->makeLoan($employee, $year->id, 200);

        $response = $this->postJson('/api/salaries', [
            'employee_id'      => $employee->id,
            'academic_year_id' => $year->id,
            'gross_amount'     => 500,
            'loan_deductions'  => [['id' => $loan->id, 'amount' => 80]],
            'period_from'      => '2025-10-01',
            'period_to'        => '2025-10-31',
            'paid_at'          => '2025-10-31',
        ]);

        $response->assertCreated();

        $salary = Salary::firstOrFail();

        $this->assertEqualsWithDelta(500, (float) $salary->gross_amount, 0.001);
        $this->assertEqualsWithDelta(80, (float) $salary->advance_deduction, 0.001);
        $this->assertEqualsWithDelta(420, (float) $salary->amount, 0.001, 'الصافي المدفوع = الخام ناقص القسط');

        $repayment = EmployeeAdvanceRepayment::firstOrFail();

        $this->assertSame($loan->id, $repayment->employee_advance_id);
        $this->assertSame($salary->id, $repayment->salary_id, 'الردّ لا يجوز أن يوجد بلا راتب يقابله');
        $this->assertSame(EmployeeAdvanceRepayment::METHOD_SALARY_DEDUCTION, $repayment->method);
        $this->assertEqualsWithDelta(80, (float) $repayment->amount, 0.001);
        $this->assertSame($year->id, $repayment->academic_year_id, 'القسط بلا سنة يسقط خارج التقارير السنوية');

        $loan->refresh();

        $this->assertEqualsWithDelta(80, (float) $loan->settled_amount, 0.001);
        $this->assertSame(EmployeeAdvance::STATUS_PARTIAL, $loan->status);
    }

    public function test_cancelling_the_salary_gives_the_loan_debt_back(): void
    {
        Sanctum::actingAs($this->makePayrollClerk());

        $year     = $this->makeAcademicYear();
        $employee = $this->makeEmployee();
        $loan     = $this->makeLoan($employee, $year->id, 200);

        $this->postJson('/api/salaries', [
            'employee_id'      => $employee->id,
            'academic_year_id' => $year->id,
            'gross_amount'     => 500,
            'loan_deductions'  => [['id' => $loan->id, 'amount' => 80]],
            'period_from'      => '2025-10-01',
            'period_to'        => '2025-10-31',
            'paid_at'          => '2025-10-31',
        ])->assertCreated();

        $salary = Salary::firstOrFail();

        $this->postJson('/api/salaries/' . $salary->id . '/cancel', [
            'reason' => 'خطأ في المبلغ الخام',
        ])->assertOk();

        $repayment = EmployeeAdvanceRepayment::firstOrFail();

        $this->assertNotNull($repayment->cancelled_at, 'الراتب لم يُدفع، فالقسط لم يُخصم من شيء');

        $loan->refresh();

        $this->assertEqualsWithDelta(0, (float) $loan->settled_amount, 0.001);
        $this->assertSame(EmployeeAdvance::STATUS_PENDING, $loan->status);
    }

    public function test_installment_bigger_than_the_remaining_debt_is_refused(): void
    {
        Sanctum::actingAs($this->makePayrollClerk());

        $year     = $this->makeAcademicYear();
        $employee = $this->makeEmployee();
        $loan     = $this->makeLoan($employee, $year->id, 200);

        $this->postJson('/api/salaries', [
            'employee_id'      => $employee->id,
            'academic_year_id' => $year->id,
            'gross_amount'     => 500,
            'loan_deductions'  => [['id' => $loan->id, 'amount' => 250]],
            'period_from'      => '2025-10-01',
            'period_to'        => '2025-10-31',
        ])->assertUnprocessable();

        $this->assertSame(0, Salary::count(), 'المعاملة تُردّ كاملة، فلا يبقى راتب يتيم');
        $this->assertSame(0, EmployeeAdvanceRepayment::count());
    }

    public function test_the_same_loan_cannot_be_deducted_twice_in_one_salary(): void
    {
        Sanctum::actingAs($this->makePayrollClerk());

        $year     = $this->makeAcademicYear();
        $employee = $this->makeEmployee();
        $loan     = $this->makeLoan($employee, $year->id, 200);

        $this->postJson('/api/salaries', [
            'employee_id'      => $employee->id,
            'academic_year_id' => $year->id,
            'gross_amount'     => 500,
            'loan_deductions'  => [
                ['id' => $loan->id, 'amount' => 50],
                ['id' => $loan->id, 'amount' => 50],
            ],
            'period_from'      => '2025-10-01',
            'period_to'        => '2025-10-31',
        ])->assertUnprocessable();

        $this->assertSame(0, Salary::count());
    }

    /** التسبقة تبقى كما كانت: خصم كامل ثم إرجاع كامل عند الإلغاء. */
    public function test_full_advance_deduction_still_works_and_reverses(): void
    {
        Sanctum::actingAs($this->makePayrollClerk());

        $year     = $this->makeAcademicYear();
        $employee = $this->makeEmployee();

        $advance = EmployeeAdvance::create([
            'employee_id'      => $employee->id,
            'academic_year_id' => $year->id,
            'type'             => EmployeeAdvance::TYPE_ADVANCE,
            'amount'           => 100,
            'settled_amount'   => 0,
            'advance_date'     => '2025-10-10',
            'status'           => EmployeeAdvance::STATUS_PENDING,
            'is_opening'       => false,
        ]);

        $this->postJson('/api/salaries', [
            'employee_id'      => $employee->id,
            'academic_year_id' => $year->id,
            'gross_amount'     => 500,
            'advance_ids'      => [$advance->id],
            'period_from'      => '2025-10-01',
            'period_to'        => '2025-10-31',
            'paid_at'          => '2025-10-31',
        ])->assertCreated();

        $salary = Salary::firstOrFail();

        $this->assertEqualsWithDelta(400, (float) $salary->amount, 0.001);

        $advance->refresh();
        $this->assertSame($salary->id, $advance->settled_by_salary_id);
        $this->assertSame(EmployeeAdvance::STATUS_SETTLED, $advance->status);

        $this->postJson('/api/salaries/' . $salary->id . '/cancel', [
            'reason' => 'تراجع عن الخلاص',
        ])->assertOk();

        $advance->refresh();

        $this->assertNull($advance->settled_by_salary_id);
        $this->assertEqualsWithDelta(0, (float) $advance->settled_amount, 0.001);
        $this->assertSame(EmployeeAdvance::STATUS_PENDING, $advance->status);
    }

    private function makePayrollClerk(): User
    {
        $user = $this->makeUser('payroll_clerk');
        $user->update(['is_active' => true]);

        $permission = Permission::firstOrCreate(
            ['name' => 'manage_salaries'],
            ['display_name' => 'إدارة الرواتب', 'group' => 'HR']
        );

        $user->role->permissions()->syncWithoutDetaching([$permission->id]);

        return $user;
    }

    private function makeEmployee(): Employee
    {
        return Employee::create([
            'first_name'     => 'خالد',
            'last_name'      => 'رابحي',
            'job_title'      => 'معلّم',
            'default_salary' => 500,
            'is_active'      => true,
        ]);
    }

    private function makeLoan(Employee $employee, int $yearId, float $amount): EmployeeAdvance
    {
        return EmployeeAdvance::create([
            'employee_id'      => $employee->id,
            'academic_year_id' => $yearId,
            'type'             => EmployeeAdvance::TYPE_LOAN,
            'amount'           => $amount,
            'settled_amount'   => 0,
            'advance_date'     => '2025-09-20',
            'reason'           => 'سلفة تُردّ على أقساط',
            'status'           => EmployeeAdvance::STATUS_PENDING,
            'is_opening'       => false,
        ]);
    }
}
