<?php

namespace Tests\Feature;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * قاعدة نوع الاستحقاق حسب تصنيف الإطار:
 *   عاملة (worker)                    → دين فقط
 *   معلم (hourly/monthly_teacher)     → دين + سلفة غير مسددة
 *   منشط (club_animator)              → دين + سلفة غير مسددة
 *   أي تصنيف آخر (مدير/قيم)           → دين فقط (الاحتياطية)
 * والقيم القديمة (salary/bonus/other) لم تعد مقبولة في إدخال جديد.
 */
class EmployeeLiabilityTypeRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs($this->adminUser());
        $this->makeAcademicYear('2026-2027');
    }

    private function adminUser()
    {
        $user = $this->makeUser('admin');
        $user->update(['is_active' => true]);

        return $user;
    }

    private function makeEmployee(string $staffType): Employee
    {
        return Employee::create([
            'first_name' => 'محمد',
            'last_name' => 'الصالحي',
            'staff_type' => $staffType,
            'is_active' => true,
        ]);
    }

    private function payload(Employee $employee, string $liabilityType): array
    {
        return [
            'employee_id' => $employee->id,
            'original_year_label' => '2024/2025',
            'liability_type' => $liabilityType,
            'description' => 'استحقاق سابق للاختبار',
            'original_amount' => 500,
        ];
    }

    public function test_worker_accepts_debt_only(): void
    {
        Sanctum::actingAs($this->adminUser());
        $worker = $this->makeEmployee('worker');

        $this->postJson('/api/employee-liabilities', $this->payload($worker, 'debt'))
            ->assertCreated();

        $this->postJson('/api/employee-liabilities', $this->payload($worker, 'advance'))
            ->assertStatus(422);
    }

    public function test_teachers_and_animator_accept_debt_and_unsettled_advance(): void
    {
        Sanctum::actingAs($this->adminUser());

        foreach (['hourly_teacher', 'monthly_teacher', 'club_animator'] as $staffType) {
            $employee = $this->makeEmployee($staffType);

            $this->postJson('/api/employee-liabilities', $this->payload($employee, 'debt'))
                ->assertCreated();

            $this->postJson('/api/employee-liabilities', $this->payload($employee, 'advance'))
                ->assertCreated();
        }
    }

    public function test_manager_and_supervisor_fall_back_to_debt_only(): void
    {
        Sanctum::actingAs($this->adminUser());

        foreach (['manager', 'supervisor'] as $staffType) {
            $employee = $this->makeEmployee($staffType);

            $this->postJson('/api/employee-liabilities', $this->payload($employee, 'debt'))
                ->assertCreated();

            $this->postJson('/api/employee-liabilities', $this->payload($employee, 'advance'))
                ->assertStatus(422);
        }
    }

    public function test_legacy_liability_values_are_rejected_on_new_entries(): void
    {
        Sanctum::actingAs($this->adminUser());
        $employee = $this->makeEmployee('monthly_teacher');

        $this->postJson('/api/employee-liabilities', $this->payload($employee, 'bonus'))
            ->assertStatus(422);
        $this->postJson('/api/employee-liabilities', $this->payload($employee, 'salary'))
            ->assertStatus(422);
        $this->postJson('/api/employee-liabilities', $this->payload($employee, 'other'))
            ->assertStatus(422);
    }

    public function test_unknown_type_is_rejected_for_any_staff_type(): void
    {
        Sanctum::actingAs($this->adminUser());
        $employee = $this->makeEmployee('worker');

        $this->postJson('/api/employee-liabilities', $this->payload($employee, 'nonsense'))
            ->assertStatus(422);
    }
}
