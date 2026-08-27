<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\OldEmployeeDebt;
use App\Models\OldEmployeeDebtCollection;
use App\Models\Permission;
use App\Models\Salary;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OldEmployeeDebtTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithPermission(string $permissionName): \App\Models\User
    {
        $user = $this->makeUser('admin');
        $user->update(['is_active' => true]);

        $permission = Permission::firstOrCreate(
            ['name' => $permissionName],
            ['display_name' => $permissionName, 'group' => 'Finance']
        );
        $user->role->permissions()->syncWithoutDetaching($permission->id);

        return $user;
    }

    private function makeEmployee(string $firstName = 'علي', string $lastName = 'الرابحي'): Employee
    {
        return Employee::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'job_title' => 'أستاذ',
            'staff_type' => 'monthly_teacher',
            'salary_type' => 'monthly',
            'monthly_salary' => '1200.00',
            'is_active' => true,
        ]);
    }

    // 1. صلاحية manage_treasury مطلوبة للـwrite operations
    public function test_manage_treasury_permission_required_for_write_operations(): void
    {
        $employee = $this->makeEmployee();
        $year = $this->makeAcademicYear();

        // مستخدم بلا صلاحية
        $unauthorizedUser = $this->makeUser('cashier');
        $unauthorizedUser->update(['is_active' => true]);
        Sanctum::actingAs($unauthorizedUser);

        $payload = [
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'debt',
            'description' => 'دين قديم سابق',
            'original_amount' => 450.00,
        ];

        $response = $this->postJson('/api/employee-opening-debts', $payload);
        $response->assertStatus(403);

        // مستخدم بصلاحية manage_treasury
        $authorizedUser = $this->makeUserWithPermission('manage_treasury');
        Sanctum::actingAs($authorizedUser);

        $response = $this->postJson('/api/employee-opening-debts', $payload);
        $response->assertStatus(201);
    }

    // 2. الإنشاء ينجح وينشئ الدين فقط، بلا CashTransaction أو Salary أو EmployeeAdvance
    public function test_creating_debt_creates_only_debt_record_without_cash_salary_or_advance(): void
    {
        $user = $this->makeUserWithPermission('manage_treasury');
        Sanctum::actingAs($user);

        $employee = $this->makeEmployee();
        $year = $this->makeAcademicYear();

        $response = $this->postJson('/api/employee-opening-debts', [
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'debt',
            'description' => 'متخلدات قديمة قبل تشغيل النظام',
            'original_amount' => 500.00,
            'notes' => 'ملاحظة افتتاحية',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('original_amount', '500.00')
            ->assertJsonPath('collected_amount', 0)
            ->assertJsonPath('outstanding_amount', 500);

        $this->assertDatabaseHas('employee_opening_debts', [
            'employee_id' => $employee->id,
            'original_amount' => '500.00',
            'status' => 'pending',
        ]);

        // لا CashTransaction
        $this->assertEquals(0, CashTransaction::count());
        // لا Collections
        $this->assertEquals(0, OldEmployeeDebtCollection::count());
        // لا Salary
        $this->assertEquals(0, Salary::count());
        // لا EmployeeAdvance
        $this->assertEquals(0, EmployeeAdvance::count());
    }

    // 3. الإنشاء لا يغير operating income ولا net income
    public function test_creating_debt_does_not_change_operating_income_or_net_income(): void
    {
        $user = $this->makeUserWithPermission('manage_treasury');
        Sanctum::actingAs($user);

        $employee = $this->makeEmployee();
        $year = $this->makeAcademicYear();

        $dashboardService = app(DashboardService::class);
        $before = $dashboardService->getDashboardData(true);

        $this->postJson('/api/employee-opening-debts', [
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'debt',
            'description' => 'دين قديم',
            'original_amount' => 600.00,
        ])->assertStatus(201);

        $after = $dashboardService->getDashboardData(true);

        // الدخل وصافي الدخل لم يتغيرا (أصفار)
        $this->assertEquals(0.0, $after['cash']['all_time']['income']);
        $this->assertEquals(0.0, $after['cash']['all_time']['net_income']);
        $this->assertEquals(0.0, $after['cash']['all_time']['balance']);
        $this->assertEquals($before['cash']['all_time']['income'], $after['cash']['all_time']['income']);
        $this->assertEquals($before['cash']['all_time']['net_income'], $after['cash']['all_time']['net_income']);

        // المتبقي في تفصيل الديون السابقة أصبح 600
        $this->assertEquals(600.0, $after['prior_debt_summary']['total_remaining']);
        $this->assertCount(1, $after['prior_debt_summary']['employee_details']);
    }

    // 4. اختبار الدفعتين: تحصيل دفعتين يسجل مبالغ تزايدية بحركتين مستقلتين
    public function test_multiple_collections_record_incremental_amounts_only(): void
    {
        $user = $this->makeUserWithPermission('manage_treasury');
        Sanctum::actingAs($user);

        $employee = $this->makeEmployee();
        $year = $this->makeAcademicYear();

        $debt = OldEmployeeDebt::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'debt',
            'description' => 'دين بقيمة 400',
            'original_amount' => 400.00,
            'status' => 'pending',
            'created_by' => $user->id,
        ]);

        // الدفعة الأولى: 150
        $res1 = $this->postJson("/api/employee-opening-debts/{$debt->id}/collect", [
            'amount' => 150.00,
            'payment_date' => '2026-08-25',
            'method' => 'cash',
            'notes' => 'دفعة أولى',
        ]);
        $res1->assertStatus(201)
            ->assertJsonPath('debt.status', 'partial')
            ->assertJsonPath('debt.collected_amount', 150)
            ->assertJsonPath('debt.outstanding_amount', 250);

        // الدفعة الثانية: 100
        $res2 = $this->postJson("/api/employee-opening-debts/{$debt->id}/collect", [
            'amount' => 100.00,
            'payment_date' => '2026-08-27',
            'method' => 'bank_transfer',
            'notes' => 'دفعة ثانية',
        ]);
        $res2->assertStatus(201)
            ->assertJsonPath('debt.status', 'partial')
            ->assertJsonPath('debt.collected_amount', 250)
            ->assertJsonPath('debt.outstanding_amount', 150);

        // التأكد من وجود سجلي collection مستقلين
        $this->assertEquals(2, OldEmployeeDebtCollection::where('employee_opening_debt_id', $debt->id)->count());
        $collections = OldEmployeeDebtCollection::where('employee_opening_debt_id', $debt->id)->orderBy('id')->get();
        $this->assertEquals('150.00', $collections[0]->amount);
        $this->assertEquals('2026-08-25', $collections[0]->payment_date->toDateString());
        $this->assertEquals('cash', $collections[0]->method);
        $this->assertEquals('100.00', $collections[1]->amount);
        $this->assertEquals('2026-08-27', $collections[1]->payment_date->toDateString());
        $this->assertEquals('bank_transfer', $collections[1]->method);

        // التأكد من وجود حركتي CashTransaction فقط بمبالغ 150 و 100
        $this->assertEquals(2, CashTransaction::count());
        $transactions = CashTransaction::orderBy('id')->get();

        $this->assertEquals('150.00', $transactions[0]->amount);
        $this->assertEquals('in', $transactions[0]->direction);
        $this->assertEquals(CashTransaction::CATEGORY_OLD_LIABILITY_COLLECTION, $transactions[0]->category);
        $this->assertEquals(OldEmployeeDebtCollection::class, $transactions[0]->source_type);
        $this->assertEquals($collections[0]->id, $transactions[0]->source_id);
        $this->assertEquals('2026-08-25', $transactions[0]->transaction_date->toDateString());

        $this->assertEquals('100.00', $transactions[1]->amount);
        $this->assertEquals('in', $transactions[1]->direction);
        $this->assertEquals(CashTransaction::CATEGORY_OLD_LIABILITY_COLLECTION, $transactions[1]->category);
        $this->assertEquals(OldEmployeeDebtCollection::class, $transactions[1]->source_type);
        $this->assertEquals($collections[1]->id, $transactions[1]->source_id);
        $this->assertEquals('2026-08-27', $transactions[1]->transaction_date->toDateString());

        // التأكد أن مجموع الحركات = 250
        $this->assertEquals(250.0, (float) CashTransaction::sum('amount'));

        // التأكد من حالة الدين
        $debt->refresh();
        $this->assertEquals(250.0, $debt->collectedAmount());
        $this->assertEquals(150.0, $debt->outstandingAmount());
        $this->assertEquals('partial', $debt->status);

        // لا رواتب ولا سلف
        $this->assertEquals(0, Salary::count());
        $this->assertEquals(0, EmployeeAdvance::count());

        // الخزينة وصافي الدخل
        $dashboardService = app(DashboardService::class);
        $dash = $dashboardService->getDashboardData(true);
        $this->assertEquals(0.0, $dash['cash']['all_time']['income']);
        $this->assertEquals(0.0, $dash['cash']['all_time']['net_income']);
        $this->assertEquals(250.0, $dash['cash']['all_time']['balance']);
        $this->assertEquals(250.0, $dash['cash']['all_time']['old_debt_collections']);
        $this->assertEquals(250.0, $dash['prior_debt_summary']['total_collected']);
        $this->assertEquals(150.0, $dash['prior_debt_summary']['total_remaining']);
    }

    // 5. التحصيل الكامل يغير الحالة إلى paid والمتبقي صفر
    public function test_full_collection_changes_status_to_paid_and_outstanding_to_zero(): void
    {
        $user = $this->makeUserWithPermission('manage_treasury');
        Sanctum::actingAs($user);

        $employee = $this->makeEmployee();
        $year = $this->makeAcademicYear();

        $debt = OldEmployeeDebt::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'debt',
            'description' => 'دين قديم',
            'original_amount' => 300.00,
            'status' => 'pending',
        ]);

        $this->postJson("/api/employee-opening-debts/{$debt->id}/collect", [
            'amount' => 300.00,
            'payment_date' => '2026-08-27',
            'method' => 'bank_transfer',
        ])->assertStatus(201)
            ->assertJsonPath('debt.status', 'paid')
            ->assertJsonPath('debt.collected_amount', 300)
            ->assertJsonPath('debt.outstanding_amount', 0);

        $debt->refresh();
        $this->assertEquals('paid', $debt->status);
        $this->assertEquals(300.0, $debt->collectedAmount());
        $this->assertEquals(0.0, $debt->outstandingAmount());

        // محاولة تحصيل إضافي بعد الاستخلاص الكامل تفشل
        $this->postJson("/api/employee-opening-debts/{$debt->id}/collect", [
            'amount' => 50.00,
        ])->assertStatus(422);

        $this->assertEquals(1, OldEmployeeDebtCollection::count());
        $this->assertEquals(1, CashTransaction::count());
    }

    // 6. تحديث كل الحقول مسموح قبل التحصيل
    public function test_updating_all_fields_allowed_before_collection(): void
    {
        $user = $this->makeUserWithPermission('manage_treasury');
        Sanctum::actingAs($user);

        $employee = $this->makeEmployee();
        $year = $this->makeAcademicYear();

        $debt = OldEmployeeDebt::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'debt',
            'description' => 'دين أولي',
            'original_amount' => 200.00,
            'notes' => 'ملاحظة قديمة',
            'status' => 'pending',
        ]);

        $response = $this->putJson("/api/employee-opening-debts/{$debt->id}", [
            'original_year_label' => '2023/2024',
            'debt_type' => 'other',
            'description' => 'دين معدل بالكامل',
            'original_amount' => 350.00,
            'notes' => 'ملاحظة جديدة',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('original_year_label', '2023/2024')
            ->assertJsonPath('debt_type', 'other')
            ->assertJsonPath('description', 'دين معدل بالكامل')
            ->assertJsonPath('original_amount', '350.00')
            ->assertJsonPath('notes', 'ملاحظة جديدة');

        $debt->refresh();
        $this->assertEquals('350.00', $debt->original_amount);
        $this->assertEquals('other', $debt->debt_type);
    }

    // 7. طلب مختلط بحقول مالية + notes بعد تحصيل يرد 422 ولا يغير أي حقل
    public function test_mixed_update_request_after_collection_returns_422_and_mutates_nothing(): void
    {
        $user = $this->makeUserWithPermission('manage_treasury');
        Sanctum::actingAs($user);

        $employee = $this->makeEmployee();
        $year = $this->makeAcademicYear();

        $debt = OldEmployeeDebt::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'debt',
            'description' => 'دين أولي',
            'original_amount' => 400.00,
            'notes' => 'ملاحظة أصلية',
            'status' => 'pending',
        ]);

        // تحصيل جزئي
        $this->postJson("/api/employee-opening-debts/{$debt->id}/collect", [
            'amount' => 100.00,
        ])->assertStatus(201);

        // محاولة تعديل مختلط (المبلغ + الملاحظات)
        $response = $this->putJson("/api/employee-opening-debts/{$debt->id}", [
            'original_amount' => 500.00,
            'notes' => 'محاولة تغيير ملاحظة مع مبلغ',
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message']);

        $debt->refresh();
        // لم يتغير المبلغ ولم تتغير الملاحظة
        $this->assertEquals('400.00', $debt->original_amount);
        $this->assertEquals('ملاحظة أصلية', $debt->notes);
    }

    // 8. notes فقط بعد تحصيل ينجح
    public function test_updating_only_notes_after_collection_succeeds(): void
    {
        $user = $this->makeUserWithPermission('manage_treasury');
        Sanctum::actingAs($user);

        $employee = $this->makeEmployee();
        $year = $this->makeAcademicYear();

        $debt = OldEmployeeDebt::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'debt',
            'description' => 'دين أولي',
            'original_amount' => 400.00,
            'notes' => 'ملاحظة أصلية',
            'status' => 'pending',
        ]);

        // تحصيل جزئي
        $this->postJson("/api/employee-opening-debts/{$debt->id}/collect", [
            'amount' => 100.00,
        ])->assertStatus(201);

        // تعديل الملاحظات فقط
        $response = $this->putJson("/api/employee-opening-debts/{$debt->id}", [
            'notes' => 'ملاحظة محدثة بعد التحصيل',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('notes', 'ملاحظة محدثة بعد التحصيل');

        $debt->refresh();
        $this->assertEquals('ملاحظة محدثة بعد التحصيل', $debt->notes);
        $this->assertEquals('400.00', $debt->original_amount);
    }

    // 9. الإلغاء قبل التحصيل ينجح مع سبب
    public function test_cancellation_before_collection_succeeds_with_reason(): void
    {
        $user = $this->makeUserWithPermission('manage_treasury');
        Sanctum::actingAs($user);

        $employee = $this->makeEmployee();
        $year = $this->makeAcademicYear();

        $debt = OldEmployeeDebt::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'debt',
            'description' => 'دين أُدخل خطأ',
            'original_amount' => 300.00,
            'status' => 'pending',
        ]);

        $response = $this->postJson("/api/employee-opening-debts/{$debt->id}/cancel", [
            'reason' => 'إدخال خاطئ بناءً على مراجعة الوثائق',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('cancellation_reason', 'إدخال خاطئ بناءً على مراجعة الوثائق')
            ->assertJsonPath('outstanding_amount', 0);

        $debt->refresh();
        $this->assertTrue($debt->isCancelled());
        $this->assertEquals('cancelled', $debt->status);
        $this->assertEquals(0.0, $debt->outstandingAmount());
    }

    // 10. الإلغاء بعد تحصيل يرد 422
    public function test_cancellation_after_collection_returns_422(): void
    {
        $user = $this->makeUserWithPermission('manage_treasury');
        Sanctum::actingAs($user);

        $employee = $this->makeEmployee();
        $year = $this->makeAcademicYear();

        $debt = OldEmployeeDebt::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'debt',
            'description' => 'دين أولي',
            'original_amount' => 500.00,
            'status' => 'pending',
        ]);

        $this->postJson("/api/employee-opening-debts/{$debt->id}/collect", [
            'amount' => 200.00,
        ])->assertStatus(201);

        $response = $this->postJson("/api/employee-opening-debts/{$debt->id}/cancel", [
            'reason' => 'محاولة إلغاء بعد قبض مال',
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message']);

        $debt->refresh();
        $this->assertFalse($debt->isCancelled());
        $this->assertEquals('partial', $debt->status);
    }

    // 11. تكرار دين نشط لنفس employee/year/type يرد 422
    public function test_duplicate_active_debt_for_same_employee_year_and_type_returns_422(): void
    {
        $user = $this->makeUserWithPermission('manage_treasury');
        Sanctum::actingAs($user);

        $employee = $this->makeEmployee();
        $year = $this->makeAcademicYear();

        // الدين الأول
        $this->postJson('/api/employee-opening-debts', [
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'debt',
            'description' => 'الدين الأول',
            'original_amount' => 300.00,
        ])->assertStatus(201);

        // محاولة إدخال دين ثانٍ نشط بنفس النوع ونفس السنة
        $response = $this->postJson('/api/employee-opening-debts', [
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'debt',
            'description' => 'دين مكرر',
            'original_amount' => 200.00,
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message']);

        // ولكن نوع مختلف (other) مسموح
        $this->postJson('/api/employee-opening-debts', [
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'other',
            'description' => 'دين من نوع آخر',
            'original_amount' => 150.00,
        ])->assertStatus(201);
    }

    // 12. DELETE وpay routes غير موجودين
    public function test_delete_and_pay_routes_do_not_exist(): void
    {
        $user = $this->makeUserWithPermission('manage_treasury');
        Sanctum::actingAs($user);

        $employee = $this->makeEmployee();
        $year = $this->makeAcademicYear();

        $debt = OldEmployeeDebt::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'debt',
            'description' => 'دين',
            'original_amount' => 300.00,
            'status' => 'pending',
        ]);

        // DELETE
        $delResponse = $this->deleteJson("/api/employee-opening-debts/{$debt->id}");
        $this->assertTrue(in_array($delResponse->status(), [404, 405], true));

        // pay route
        $payResponse = $this->postJson("/api/employee-opening-debts/{$debt->id}/pay", ['amount' => 100]);
        $this->assertTrue(in_array($payResponse->status(), [404, 405], true));
    }

    // 13. تحصيل أكبر من المتبقي يرد 422 ولا ينشئ حركة
    public function test_collecting_more_than_outstanding_returns_422(): void
    {
        $user = $this->makeUserWithPermission('manage_treasury');
        Sanctum::actingAs($user);

        $employee = $this->makeEmployee();
        $year = $this->makeAcademicYear();

        $debt = OldEmployeeDebt::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'debt',
            'description' => 'دين',
            'original_amount' => 200.00,
            'status' => 'pending',
        ]);

        $response = $this->postJson("/api/employee-opening-debts/{$debt->id}/collect", [
            'amount' => 250.00,
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->assertEquals(0, OldEmployeeDebtCollection::count());
        $this->assertEquals(0, CashTransaction::count());
    }

    // 14. التحصيل من دين ملغى يرد 422 ولا ينشئ حركة
    public function test_collecting_cancelled_debt_returns_422(): void
    {
        $user = $this->makeUserWithPermission('manage_treasury');
        Sanctum::actingAs($user);

        $employee = $this->makeEmployee();
        $year = $this->makeAcademicYear();

        $debt = OldEmployeeDebt::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'debt',
            'description' => 'دين ملغى',
            'original_amount' => 200.00,
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => 'ملغى مسبقاً',
        ]);

        $response = $this->postJson("/api/employee-opening-debts/{$debt->id}/collect", [
            'amount' => 100.00,
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->assertEquals(0, OldEmployeeDebtCollection::count());
        $this->assertEquals(0, CashTransaction::count());
    }

    // 15. منع نقل الدين دون تعديل مختلط
    public function test_update_rejects_employee_or_academic_year_changes_without_mutating_notes(): void
    {
        $user = $this->makeUserWithPermission('manage_treasury');
        Sanctum::actingAs($user);

        $employee1 = $this->makeEmployee('أحمد', 'الطرابلسي');
        $employee2 = $this->makeEmployee('سمير', 'المحمودي');

        $year1 = $this->makeAcademicYear('2024/2025');
        $year2 = $this->makeAcademicYear('2025/2026');

        $debt = OldEmployeeDebt::create([
            'employee_id' => $employee1->id,
            'academic_year_id' => $year1->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'debt',
            'description' => 'دين أصلي',
            'original_amount' => 400.00,
            'notes' => 'ملاحظة أصلية',
            'status' => 'pending',
        ]);

        $response = $this->putJson("/api/employee-opening-debts/{$debt->id}", [
            'employee_id' => $employee2->id,
            'academic_year_id' => $year2->id,
            'notes' => 'يجب ألا تحفظ',
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message']);

        $debt->refresh();
        $this->assertEquals($employee1->id, $debt->employee_id);
        $this->assertEquals($year1->id, $debt->academic_year_id);
        $this->assertEquals('ملاحظة أصلية', $debt->notes);
        $this->assertEquals(0, CashTransaction::count());
        $this->assertEquals(0, OldEmployeeDebtCollection::count());
        $this->assertEquals(0, Salary::count());
        $this->assertEquals(0, EmployeeAdvance::count());
    }

    // 16. حماية pagination
    public function test_index_clamps_invalid_per_page_to_at_least_one(): void
    {
        $user = $this->makeUserWithPermission('manage_treasury');
        Sanctum::actingAs($user);

        $employee = $this->makeEmployee();
        $year = $this->makeAcademicYear();

        OldEmployeeDebt::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'debt',
            'description' => 'الدين الأول',
            'original_amount' => 100.00,
            'status' => 'pending',
        ]);

        OldEmployeeDebt::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'other',
            'description' => 'الدين الثاني',
            'original_amount' => 200.00,
            'status' => 'pending',
        ]);

        // per_page=0 -> clamps to 1
        $res0 = $this->getJson('/api/employee-opening-debts?per_page=0');
        $res0->assertStatus(200)
            ->assertJsonPath('per_page', 1)
            ->assertJsonCount(1, 'data');

        // per_page=-5 -> clamps to 1
        $resNegative = $this->getJson('/api/employee-opening-debts?per_page=-5');
        $resNegative->assertStatus(200)
            ->assertJsonPath('per_page', 1)
            ->assertJsonCount(1, 'data');

        // per_page=999 -> clamps to 100
        $resMax = $this->getJson('/api/employee-opening-debts?per_page=999');
        $resMax->assertStatus(200)
            ->assertJsonPath('per_page', 100);
    }
}