<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeDailyHour;
use App\Models\Permission;
use App\Models\Salary;
use App\Services\EmployeeHoursService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeHoursTest extends TestCase
{
    use RefreshDatabase;

    private EmployeeHoursService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EmployeeHoursService::class);
    }

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

    private function makeHourlyTeacher(float $rate = 10): Employee
    {
        return Employee::create([
            'first_name' => 'سامي',
            'last_name' => 'الساعي',
            'staff_type' => 'hourly_teacher',
            'salary_type' => 'hourly',
            'hourly_rate' => $rate,
            'is_active' => true,
        ]);
    }

    public function test_week_of_work_is_summed_and_salary_computed(): void
    {
        $this->makeActiveUserWithPermission('manage_employees');
        $teacher = $this->makeHourlyTeacher(10);

        // أسبوع كامل: الاثنين → السبت، 6 ساعات يومياً.
        foreach (['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06', '2026-03-07'] as $date) {
            $this->postJson("/api/employees/{$teacher->id}/hours", [
                'work_date' => $date,
                'hours' => 6,
                'note_type' => 'normal',
            ])->assertOk();
        }

        $summary = $this->service->getMonthlyHours($teacher->id, 2026, 3);

        $this->assertSame(36.0, $summary['total_hours']);
        $this->assertSame(360.0, $summary['total_salary']);
        $this->assertSame(6, $summary['work_days']);
        $this->assertSame(0, $summary['absence_days']);

        $this->getJson("/api/employees/{$teacher->id}/hours/monthly-summary?month=2026-03")
            ->assertOk()
            ->assertJson(['total_hours' => 36.0, 'total_salary' => 360.0]);
    }

    public function test_absence_day_is_subtracted_from_total(): void
    {
        $this->makeActiveUserWithPermission('manage_employees');
        $teacher = $this->makeHourlyTeacher(10);

        // 4 أيام عمل (24 ساعة) ثم غياب يوم كامل (6 ساعات).
        foreach (['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05'] as $date) {
            $this->postJson("/api/employees/{$teacher->id}/hours", [
                'work_date' => $date, 'hours' => 6, 'note_type' => 'normal',
            ])->assertOk();
        }

        $this->postJson("/api/employees/{$teacher->id}/hours", [
            'work_date' => '2026-03-06', 'hours' => 6, 'note_type' => 'absence',
            'notes' => 'مريض',
        ])->assertOk();

        $summary = $this->service->getMonthlyHours($teacher->id, 2026, 3);

        $this->assertSame(18.0, $summary['total_hours']);
        $this->assertSame(180.0, $summary['total_salary']);
        $this->assertSame(1, $summary['absence_days']);
        $this->assertSame(4, $summary['work_days']);
    }

    public function test_replacement_and_extra_days_add_hours(): void
    {
        $this->makeActiveUserWithPermission('manage_employees');
        $teacher = $this->makeHourlyTeacher(10);

        $this->postJson("/api/employees/{$teacher->id}/hours", [
            'work_date' => '2026-03-02', 'hours' => 6, 'note_type' => 'normal',
        ])->assertOk();

        // تعويض عن معلم آخر + 3 ساعات، وإضافية + 2.
        $this->postJson("/api/employees/{$teacher->id}/hours", [
            'work_date' => '2026-03-03', 'hours' => 3, 'note_type' => 'replacement',
        ])->assertOk();

        $this->postJson("/api/employees/{$teacher->id}/hours", [
            'work_date' => '2026-03-04', 'hours' => 2, 'note_type' => 'extra',
        ])->assertOk();

        $summary = $this->service->getMonthlyHours($teacher->id, 2026, 3);

        $this->assertSame(11.0, $summary['total_hours']);
        $this->assertSame(110.0, $summary['total_salary']);
        $this->assertSame(3, $summary['work_days']);
    }

    public function test_unique_constraint_keeps_one_row_per_day_upsert(): void
    {
        $this->makeActiveUserWithPermission('manage_employees');
        $teacher = $this->makeHourlyTeacher(10);

        $this->postJson("/api/employees/{$teacher->id}/hours", [
            'work_date' => '2026-03-02', 'hours' => 6, 'note_type' => 'normal',
        ])->assertOk();

        // إعادة تسجيل اليوم نفسه تُعدّل الصف بدل إنشاء ثانٍ.
        $this->postJson("/api/employees/{$teacher->id}/hours", [
            'work_date' => '2026-03-02', 'hours' => 7, 'note_type' => 'normal',
        ])->assertOk();

        $this->assertSame(1, EmployeeDailyHour::where('employee_id', $teacher->id)
            ->where('work_date', '2026-03-02')->count());

        $this->assertDatabaseHas('employee_daily_hours', [
            'employee_id' => $teacher->id, 'work_date' => '2026-03-02', 'hours' => '7.00',
        ]);

        // ولا تكرار على مستوى الجدول كلّه لنفس اليوم والمعلم.
        $this->assertSame(
            1,
            EmployeeDailyHour::where('employee_id', $teacher->id)->count()
        );
    }

    public function test_monthly_summary_feeds_salary_gross_amount_for_hourly_teacher(): void
    {
        $this->makeActiveUserWithPermission('manage_employees');
        $year = $this->makeAcademicYear();
        $teacher = $this->makeHourlyTeacher(12.5);

        // 4 أيام × 6 ساعات = 24 ساعة × 12.5 = 300.
        foreach (['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05'] as $date) {
            $this->postJson("/api/employees/{$teacher->id}/hours", [
                'work_date' => $date, 'hours' => 6, 'note_type' => 'normal',
            ])->assertOk();
        }

        // ما تعرضه النافذة للمسؤول: الملخص الشهري هو الراتب المقترح.
        $summary = $this->service->getMonthlyHours($teacher->id, 2026, 3);
        $this->assertSame(300.0, $summary['total_salary']);

        // الخلاص اليدوي عبر SalaryController يمرّ بنفس القيمة المقترحة.
        $salary = Salary::create([
            'employee_id' => $teacher->id,
            'academic_year_id' => $year->id,
            'gross_amount' => (string) $summary['total_salary'],
            'advance_deduction' => '0.00',
            'amount' => (string) $summary['total_salary'],
            'period_from' => '2026-03-01',
            'period_to' => '2026-03-31',
        ]);

        $this->assertDatabaseHas('salaries', [
            'employee_id' => $teacher->id,
            'gross_amount' => '300.00',
            'amount' => '300.00',
        ]);
        $this->assertSame($summary['total_salary'], (float) $salary->gross_amount);
    }
}