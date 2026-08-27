<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeLiability;
use App\Models\Permission;
use App\Models\Salary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeLiabilityUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithPermissions(string $roleName, array $permissions): User
    {
        $user = $this->makeUser($roleName);
        $user->update(['is_active' => true]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['display_name' => $name, 'group' => 'Test']
            );
            $user->role->permissions()->syncWithoutDetaching($permission->id);
        }

        return $user;
    }

    public function test_put_employee_liability_requires_manage_treasury_permission(): void
    {
        $year = $this->makeAcademicYear();
        $employee = Employee::create([
            'first_name' => 'علي',
            'last_name' => 'الرابحي',
            'staff_type' => 'worker',
            'is_active' => true,
        ]);

        $liability = EmployeeLiability::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'liability_type' => 'debt',
            'description' => 'دين قديم',
            'original_amount' => 500.00,
            'status' => EmployeeLiability::STATUS_PENDING,
        ]);

        // 1. مستخدم بصلاحيات محاسب عادي (بدون manage_treasury) -> 403
        Sanctum::actingAs($this->makeUserWithPermissions('cashier', ['manage_payments']));
        $this->putJson("/api/employee-liabilities/{$liability->id}", [
            'notes' => 'ملاحظة تجريبية',
        ])->assertForbidden();

        // 2. مستخدم بصلاحية manage_treasury -> 200
        Sanctum::actingAs($this->makeUserWithPermissions('treasurer', ['manage_treasury']));
        $this->putJson("/api/employee-liabilities/{$liability->id}", [
            'notes' => 'ملاحظة صحيحة',
        ])->assertOk();
    }

    public function test_uncollected_liability_allows_updating_all_fields(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('treasurer', ['manage_treasury']));
        $year = $this->makeAcademicYear();
        $employee = Employee::create([
            'first_name' => 'محمد',
            'last_name' => 'الورتاني',
            'staff_type' => 'monthly_teacher',
            'is_active' => true,
        ]);

        $liability = EmployeeLiability::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'liability_type' => 'debt',
            'description' => 'سلفة قديمة',
            'original_amount' => 300.00,
            'status' => EmployeeLiability::STATUS_PENDING,
        ]);

        $this->putJson("/api/employee-liabilities/{$liability->id}", [
            'original_amount' => 450.00,
            'liability_type' => 'advance',
            'description' => 'سلفة غير مسددة محدثة',
            'notes' => 'ملاحظة الإدارة بعد المراجعة',
        ])->assertOk();

        $liability->refresh();
        $this->assertEquals(450.00, (float) $liability->original_amount);
        $this->assertEquals('advance', $liability->liability_type);
        $this->assertEquals('سلفة غير مسددة محدثة', $liability->description);
        $this->assertEquals('ملاحظة الإدارة بعد المراجعة', $liability->notes);
    }

    public function test_uncollected_liability_validates_staff_type_compatibility(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('treasurer', ['manage_treasury']));
        $year = $this->makeAcademicYear();
        $worker = Employee::create([
            'first_name' => 'علي',
            'last_name' => 'الرابحي',
            'staff_type' => 'worker',
            'is_active' => true,
        ]);

        $liability = EmployeeLiability::create([
            'employee_id' => $worker->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'liability_type' => 'debt',
            'description' => 'دين عامل',
            'original_amount' => 200.00,
            'status' => EmployeeLiability::STATUS_PENDING,
        ]);

        // محاولة تحويل دين العاملة إلى سلفة advance -> مرفوض 422
        $this->putJson("/api/employee-liabilities/{$liability->id}", [
            'liability_type' => 'advance',
        ])->assertStatus(422);

        $liability->refresh();
        $this->assertEquals('debt', $liability->liability_type);
    }

    public function test_collected_liability_rejects_financial_field_changes(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('treasurer', ['manage_treasury']));
        $year = $this->makeAcademicYear();
        $employee = Employee::create([
            'first_name' => 'علي',
            'last_name' => 'الرابحي',
            'staff_type' => 'worker',
            'is_active' => true,
        ]);

        $liability = EmployeeLiability::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'liability_type' => 'debt',
            'description' => 'دين عامل أصلي',
            'original_amount' => 500.00,
            'notes' => 'ملاحظة أصلية',
            'status' => EmployeeLiability::STATUS_PENDING,
        ]);

        // تحصيل جزئي: 200 د.ت
        $this->postJson("/api/employee-liabilities/{$liability->id}/collect", [
            'amount' => 200.00,
            'paid_at' => now()->toDateString(),
            'method' => 'cash',
        ])->assertStatus(201);

        $liability->refresh();
        $this->assertEquals(200.00, $liability->paid());

        $cashCountBefore = CashTransaction::count();
        $salaryCountBefore = Salary::count();
        $advanceCountBefore = EmployeeAdvance::count();

        // إرسال حقول مالية محظورة + ملاحظات لدين محصل -> 422 Unprocessable Entity
        $response = $this->putJson("/api/employee-liabilities/{$liability->id}", [
            'original_amount' => 9999.00,
            'liability_type' => 'advance',
            'description' => 'تغيير غير مسموح',
            'notes' => 'محاولة تعديل ملاحظة مع حقول مالية',
        ])->assertStatus(422);

        $response->assertJsonValidationErrors(['original_amount', 'liability_type', 'description']);

        // التأكد من عدم تغير أي حقل في الالتزام، بما في ذلك الملاحظات
        $liability->refresh();
        $this->assertEquals(500.00, (float) $liability->original_amount);
        $this->assertEquals('debt', $liability->liability_type);
        $this->assertEquals('دين عامل أصلي', $liability->description);
        $this->assertEquals('ملاحظة أصلية', $liability->notes);

        // التأكد من عدم إنشاء أي قيود مالية
        $this->assertEquals($cashCountBefore, CashTransaction::count());
        $this->assertEquals($salaryCountBefore, Salary::count());
        $this->assertEquals($advanceCountBefore, EmployeeAdvance::count());
    }

    public function test_collected_liability_allows_updating_notes_only(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('treasurer', ['manage_treasury']));
        $year = $this->makeAcademicYear();
        $employee = Employee::create([
            'first_name' => 'علي',
            'last_name' => 'الرابحي',
            'staff_type' => 'worker',
            'is_active' => true,
        ]);

        $liability = EmployeeLiability::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'liability_type' => 'debt',
            'description' => 'دين عامل أصلي',
            'original_amount' => 500.00,
            'notes' => 'ملاحظة أصلية',
            'status' => EmployeeLiability::STATUS_PENDING,
        ]);

        // تحصيل جزئي: 200 د.ت
        $this->postJson("/api/employee-liabilities/{$liability->id}/collect", [
            'amount' => 200.00,
            'paid_at' => now()->toDateString(),
            'method' => 'cash',
        ])->assertStatus(201);

        $liability->refresh();
        $this->assertEquals(200.00, $liability->paid());

        $cashCountBefore = CashTransaction::count();
        $salaryCountBefore = Salary::count();
        $advanceCountBefore = EmployeeAdvance::count();

        // إرسال ملاحظات فقط لدين محصل -> 200 OK
        $this->putJson("/api/employee-liabilities/{$liability->id}", [
            'notes' => 'ملاحظة مسموحة ومحدثة',
        ])->assertOk();

        // التأكد من تحديث الملاحظات مع ثبات الحقول المالية
        $liability->refresh();
        $this->assertEquals(500.00, (float) $liability->original_amount);
        $this->assertEquals('debt', $liability->liability_type);
        $this->assertEquals('دين عامل أصلي', $liability->description);
        $this->assertEquals('ملاحظة مسموحة ومحدثة', $liability->notes);

        // التأكد من عدم إنشاء أي قيود مالية
        $this->assertEquals($cashCountBefore, CashTransaction::count());
        $this->assertEquals($salaryCountBefore, Salary::count());
        $this->assertEquals($advanceCountBefore, EmployeeAdvance::count());
    }

    public function test_cancelled_liability_returns_422_on_update(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('treasurer', ['manage_treasury']));
        $year = $this->makeAcademicYear();
        $employee = Employee::create([
            'first_name' => 'صالح',
            'last_name' => 'التونسي',
            'staff_type' => 'monthly_teacher',
            'is_active' => true,
        ]);

        $liability = EmployeeLiability::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'liability_type' => 'debt',
            'description' => 'دين ملغى',
            'original_amount' => 150.00,
            'status' => EmployeeLiability::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => 'إلغاء لخطأ في الإدخال',
        ]);

        $this->putJson("/api/employee-liabilities/{$liability->id}", [
            'notes' => 'محاولة تعديل ملغى',
        ])->assertStatus(422);
    }

    public function test_no_delete_endpoint_for_employee_liabilities(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('treasurer', ['manage_treasury']));
        $year = $this->makeAcademicYear();
        $employee = Employee::create([
            'first_name' => 'علي',
            'last_name' => 'الرابحي',
            'staff_type' => 'worker',
            'is_active' => true,
        ]);

        $liability = EmployeeLiability::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'liability_type' => 'debt',
            'description' => 'دين',
            'original_amount' => 100.00,
            'status' => EmployeeLiability::STATUS_PENDING,
        ]);

        // لا يوجد مسار DELETE (يعيد 404 أو 405)
        $response = $this->deleteJson("/api/employee-liabilities/{$liability->id}");
        $this->assertTrue(in_array($response->getStatusCode(), [404, 405], true));
    }

    public function test_update_does_not_create_cash_transactions_salaries_or_advances(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('treasurer', ['manage_treasury']));
        $year = $this->makeAcademicYear();
        $employee = Employee::create([
            'first_name' => 'نور',
            'last_name' => 'الدين',
            'staff_type' => 'monthly_teacher',
            'is_active' => true,
        ]);

        $liability = EmployeeLiability::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'liability_type' => 'debt',
            'description' => 'دين',
            'original_amount' => 250.00,
            'status' => EmployeeLiability::STATUS_PENDING,
        ]);

        $cashCountBefore = CashTransaction::count();
        $salaryCountBefore = Salary::count();
        $advanceCountBefore = EmployeeAdvance::count();

        $this->putJson("/api/employee-liabilities/{$liability->id}", [
            'original_amount' => 300.00,
            'notes' => 'تعديل بسيط',
        ])->assertOk();

        $this->assertEquals($cashCountBefore, CashTransaction::count());
        $this->assertEquals($salaryCountBefore, Salary::count());
        $this->assertEquals($advanceCountBefore, EmployeeAdvance::count());
    }
}
