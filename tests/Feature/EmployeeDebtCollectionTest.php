<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Employee;
use App\Models\EmployeeLiability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeDebtCollectionTest extends TestCase
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

    private function createDebt(Employee $emp, AcademicYear $year, float $amount = 500): EmployeeLiability
    {
        $res = $this->postJson('/api/employee-liabilities', [
            'employee_id' => $emp->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'liability_type' => 'debt',
            'description' => 'دين قديم للاختبار',
            'original_amount' => $amount,
        ])->assertCreated();
        return EmployeeLiability::findOrFail($res->json('id'));
    }

    public function test_create_debt_does_not_create_cash(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear('2025-2026');
        $emp = $this->makeEmployee('worker');
        $this->postJson('/api/employee-liabilities', [
            'employee_id' => $emp->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'liability_type' => 'debt',
            'description' => 'دين',
            'original_amount' => 500,
        ])->assertCreated();
        $this->assertDatabaseCount('cash_transactions', 0);
    }

    public function test_collect_creates_in_old_liability_collection(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear('2025-2026');
        $emp = $this->makeEmployee('worker');
        $liab = $this->createDebt($emp, $year, 500);
        $this->postJson("/api/employee-liabilities/{$liab->id}/collect", [
            'amount' => 200,
        ])->assertCreated();
        $this->assertDatabaseHas('cash_transactions', [
            'category' => CashTransaction::CATEGORY_OLD_LIABILITY_COLLECTION,
            'direction' => CashTransaction::DIRECTION_IN,
            'amount' => 200,
        ]);
    }

    public function test_collect_does_not_create_salary(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear('2025-2026');
        $emp = $this->makeEmployee('worker');
        $liab = $this->createDebt($emp, $year, 500);
        $this->postJson("/api/employee-liabilities/{$liab->id}/collect", ['amount' => 200])->assertCreated();
        $this->assertDatabaseCount('salaries', 0);
    }

    public function test_collect_decreases_outstanding(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear('2025-2026');
        $emp = $this->makeEmployee('worker');
        $liab = $this->createDebt($emp, $year, 500);
        $this->postJson("/api/employee-liabilities/{$liab->id}/collect", ['amount' => 200])->assertCreated();
        $this->assertEqualsWithDelta(300, $liab->fresh()->outstanding(), 0.01);
        $this->assertEqualsWithDelta(200, $liab->fresh()->paid(), 0.01);
    }

    public function test_collect_increases_cash_in(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear('2025-2026');
        $emp = $this->makeEmployee('worker');
        $liab = $this->createDebt($emp, $year, 500);
        $before = (float) CashTransaction::whereIn('category', CashTransaction::OLD_DEBT_COLLECTION_CATEGORIES)->sum('amount');
        $this->postJson("/api/employee-liabilities/{$liab->id}/collect", ['amount' => 200])->assertCreated();
        $after = (float) CashTransaction::whereIn('category', CashTransaction::OLD_DEBT_COLLECTION_CATEGORIES)->sum('amount');
        $this->assertEqualsWithDelta(200, $after - $before, 0.01);
        // cash_in via DashboardService
        $svc = app(\App\Services\DashboardService::class);
        $ref = new \ReflectionMethod($svc, 'cashFigures');
        $ref->setAccessible(true);
        $fig = $ref->invoke($svc, null, now()->toDateString());
        $this->assertEqualsWithDelta(200, $fig['old_debt_collections'], 0.01);
        $this->assertEqualsWithDelta(200, $fig['cash_in'] - $fig['current_year_income'], 0.01);
    }

    public function test_collect_does_not_increase_cash_out(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear('2025-2026');
        $emp = $this->makeEmployee('worker');
        $liab = $this->createDebt($emp, $year, 500);
        $before = (float) CashTransaction::whereIn('category', CashTransaction::EXPENSE_CATEGORIES)->sum('amount');
        $beforeOut = (float) CashTransaction::where('direction','out')->sum('amount');
        $this->postJson("/api/employee-liabilities/{$liab->id}/collect", ['amount'=>200])->assertCreated();
        $after = (float) CashTransaction::whereIn('category', CashTransaction::EXPENSE_CATEGORIES)->sum('amount');
        $afterOutExp = (float) CashTransaction::whereIn('category', [CashTransaction::CATEGORY_SALARY, CashTransaction::CATEGORY_EXPENSE])->where('direction','out')->sum('amount');
        $this->assertEqualsWithDelta($before, $after, 0.01);
    }

    public function test_collect_does_not_increase_expenses(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear('2025-2026');
        $emp = $this->makeEmployee('worker');
        $liab = $this->createDebt($emp, $year, 500);
        $before = (float) CashTransaction::whereIn('category', CashTransaction::EXPENSE_CATEGORIES)->whereNull('cancelled_at')->sum('amount');
        $this->postJson("/api/employee-liabilities/{$liab->id}/collect", ['amount'=>200])->assertCreated();
        $after = (float) CashTransaction::whereIn('category', CashTransaction::EXPENSE_CATEGORIES)->whereNull('cancelled_at')->sum('amount');
        $this->assertEqualsWithDelta(0, $after - $before, 0.01);
    }

    public function test_collect_does_not_add_current_year_income(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear('2025-2026');
        $emp = $this->makeEmployee('worker');
        $liab = $this->createDebt($emp, $year, 500);
        $svc = app(\App\Services\DashboardService::class);
        $ref = new \ReflectionMethod($svc, 'cashFigures');
        $ref->setAccessible(true);
        $before = $ref->invoke($svc, null, now()->toDateString());
        $this->postJson("/api/employee-liabilities/{$liab->id}/collect", ['amount'=>200])->assertCreated();
        $after = $ref->invoke($svc, null, now()->toDateString());
        $this->assertEqualsWithDelta($before['current_year_income'], $after['current_year_income'], 0.01);
        $this->assertEqualsWithDelta($before['income'], $after['income'], 0.01);
    }

    public function test_collect_does_not_add_net_income_as_new_income(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear('2025-2026');
        $emp = $this->makeEmployee('worker');
        $liab = $this->createDebt($emp, $year, 500);
        $svc = app(\App\Services\DashboardService::class);
        $ref = new \ReflectionMethod($svc, 'cashFigures');
        $ref->setAccessible(true);
        $before = $ref->invoke($svc, null, now()->toDateString());
        $this->postJson("/api/employee-liabilities/{$liab->id}/collect", ['amount'=>200])->assertCreated();
        $after = $ref->invoke($svc, null, now()->toDateString());
        // net_income = income - expenses, should not increase by collection
        $this->assertEqualsWithDelta($before['net_income'], $after['net_income'], 0.01);
        // but balance should increase
        $this->assertEqualsWithDelta($before['balance'] + 200, $after['balance'], 0.01);
    }

    public function test_pay_rejects_debt(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear('2025-2026');
        $emp = $this->makeEmployee('worker');
        $liab = $this->createDebt($emp, $year, 500);
        $response = $this->postJson("/api/employee-liabilities/{$liab->id}/pay", ['amount'=>100]);
        $response->assertStatus(422);
        $this->assertStringContainsString('هذا دين مستحق للمؤسسة', $response->json('message') ?? '');
    }

    public function test_pay_still_works_for_payable_liability(): void
    {
        // legacy payable type simulation: create directly with advance? advance is also debt per current matrix, but pay should work for non-debt if existed.
        // For now test that pay works when liability_type is not debt by creating via DB directly with legacy type 'salary' (allowed via DB but not via API).
        // Instead we test that pay endpoint exists and rejects debt but would accept if we bypass type check - we verify pay route still accessible.
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear('2025-2026');
        $emp = $this->makeEmployee('worker');
        // create a liability with type 'salary' directly (legacy)
        $liab = EmployeeLiability::create([
            'employee_id'=>$emp->id,'academic_year_id'=>$year->id,
            'original_year_label'=>'2024/2025','liability_type'=>'salary',
            'description'=>'مستحق فعلي','original_amount'=>400,'status'=>EmployeeLiability::STATUS_PENDING,
        ]);
        $this->postJson("/api/employee-liabilities/{$liab->id}/pay", ['amount'=>100])->assertCreated();
        $this->assertDatabaseHas('cash_transactions',['category'=>CashTransaction::CATEGORY_OLD_LIABILITY_PAYMENT]);
    }

    public function test_rejects_zero_and_negative(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear('2025-2026');
        $emp = $this->makeEmployee('worker');
        $liab = $this->createDebt($emp, $year, 500);
        $this->postJson("/api/employee-liabilities/{$liab->id}/collect", ['amount'=>0])->assertStatus(422);
        $this->postJson("/api/employee-liabilities/{$liab->id}/collect", ['amount'=>-10])->assertStatus(422);
    }

    public function test_rejects_exceeding_outstanding(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear('2025-2026');
        $emp = $this->makeEmployee('worker');
        $liab = $this->createDebt($emp, $year, 300);
        $this->postJson("/api/employee-liabilities/{$liab->id}/collect", ['amount'=>400])->assertStatus(422);
    }

    public function test_rejects_collect_after_paid(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear('2025-2026');
        $emp = $this->makeEmployee('worker');
        $liab = $this->createDebt($emp, $year, 200);
        $this->postJson("/api/employee-liabilities/{$liab->id}/collect", ['amount'=>200])->assertCreated();
        $this->assertSame(EmployeeLiability::STATUS_PAID, $liab->fresh()->status);
        $this->postJson("/api/employee-liabilities/{$liab->id}/collect", ['amount'=>10])->assertStatus(422);
    }

    public function test_cancel_collect_restores_outstanding(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear('2025-2026');
        $emp = $this->makeEmployee('worker');
        $liab = $this->createDebt($emp, $year, 500);
        $this->postJson("/api/employee-liabilities/{$liab->id}/collect", ['amount'=>200])->assertCreated();
        $this->assertEqualsWithDelta(300, $liab->fresh()->outstanding(), 0.01);
        // cancel via model? use ledger cancel? Collect creates aggregated row source=liability, cancelFor will cancel it
        $liab->refresh();
        app(\App\Services\LedgerService::class)->cancelFor($liab, null, 'تصحيح');
        // after cancel, paid should be 0
        $this->assertEqualsWithDelta(0, $liab->fresh()->paid(), 0.01);
        $this->assertEqualsWithDelta(500, $liab->fresh()->outstanding(), 0.01);
        // record should be cancelled not deleted
        $this->assertDatabaseHas('cash_transactions',['source_type'=>$liab->getMorphClass(),'source_id'=>$liab->id,'category'=>CashTransaction::CATEGORY_OLD_LIABILITY_COLLECTION]);
        $cancelled = CashTransaction::where('source_type',$liab->getMorphClass())->where('source_id',$liab->id)->first();
        $this->assertNotNull($cancelled->cancelled_at);
    }

    public function test_old_out_payment_not_counted_as_new_collection(): void
    {
        Sanctum::actingAs($this->admin());
        $year = $this->makeAcademicYear('2025-2026');
        $emp = $this->makeEmployee('worker');
        $liab = $this->createDebt($emp, $year, 500);
        // legacy OUT record
        CashTransaction::create([
            'transaction_date'=>now()->toDateString(),
            'direction'=>CashTransaction::DIRECTION_OUT,
            'category'=>CashTransaction::CATEGORY_OLD_LIABILITY_PAYMENT,
            'amount'=>500,
            'source_type'=>$liab->getMorphClass(),
            'source_id'=>$liab->id,
            'academic_year_id'=>$year->id,
        ]);
        // paid should still be 0 because we now count only IN collection
        $this->assertEqualsWithDelta(0, $liab->fresh()->paid(), 0.01);
        $this->assertEqualsWithDelta(500, $liab->fresh()->outstanding(), 0.01);
        // new collect should still work
        $this->postJson("/api/employee-liabilities/{$liab->id}/collect", ['amount'=>200])->assertCreated();
        $this->assertEqualsWithDelta(200, $liab->fresh()->paid(), 0.01);
    }

    public function test_permission(): void
    {
        $user = $this->makeUser('cashier');
        $user->update(['is_active'=>true]);
        Sanctum::actingAs($user);
        $year = $this->makeAcademicYear('2025-2026');
        $emp = $this->makeEmployee('worker');
        // need treasury permission, cashier lacks it
        $liab = EmployeeLiability::create([
            'employee_id'=>$emp->id,'academic_year_id'=>$year->id,
            'original_year_label'=>'2024/2025','liability_type'=>'debt',
            'description'=>'دين','original_amount'=>300,'status'=>EmployeeLiability::STATUS_PENDING,
        ]);
        $this->postJson("/api/employee-liabilities/{$liab->id}/collect", ['amount'=>100])->assertStatus(403);
        $this->getJson("/api/employee-liabilities")->assertStatus(403);
    }
}
