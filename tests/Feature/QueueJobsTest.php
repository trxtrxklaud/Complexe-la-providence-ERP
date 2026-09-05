<?php

namespace Tests\Feature;

use App\Jobs\ProcessBulkEnrollment;
use App\Jobs\ProcessSalaryCalculation;
use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Enrollment;
use App\Models\Level;
use App\Models\Permission;
use App\Models\Salary;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QueueJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_salary_calculation_job_handles_single_salary_and_ledger(): void
    {
        $user = $this->makeUserWithPermission('manage_salaries');
        $year = $this->makeAcademicYear();
        $employee = Employee::create([
            'first_name' => 'كمال',
            'last_name' => 'الطرابلسي',
            'default_salary' => 600,
            'is_active' => true,
        ]);

        $advance = EmployeeAdvance::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'type' => EmployeeAdvance::TYPE_ADVANCE,
            'amount' => 100,
            'settled_amount' => 0,
            'advance_date' => '2025-10-15',
            'reason' => 'تسبقة',
            'status' => EmployeeAdvance::STATUS_PENDING,
            'is_opening' => false,
        ]);

        $data = [
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'gross_amount' => 600,
            'advance_ids' => [$advance->id],
            'period_from' => '2025-10-01',
            'period_to' => '2025-10-31',
            'paid_at' => '2025-10-31',
        ];

        $salary = (new ProcessSalaryCalculation($data, $user->id))->handle(app(LedgerService::class));

        $this->assertInstanceOf(Salary::class, $salary);
        $this->assertEquals(600, (float) $salary->gross_amount);
        $this->assertEquals(100, (float) $salary->advance_deduction);
        $this->assertEquals(500, (float) $salary->amount);

        $advance->refresh();
        $this->assertSame(EmployeeAdvance::STATUS_SETTLED, $advance->status);
        $this->assertSame($salary->id, $advance->settled_by_salary_id);

        $this->assertDatabaseHas('cash_transactions', [
            'source_type' => Salary::class,
            'source_id' => $salary->id,
            'category' => CashTransaction::CATEGORY_SALARY,
            'direction' => CashTransaction::DIRECTION_OUT,
            'amount' => 500,
        ]);
    }

    public function test_salary_controller_dispatches_job_when_async_flag_is_true(): void
    {
        Queue::fake([ProcessSalaryCalculation::class]);

        $user = $this->makeUserWithPermission('manage_salaries');
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear();
        $employee = Employee::create([
            'first_name' => 'سامي',
            'last_name' => 'الدريدي',
            'default_salary' => 700,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/salaries', [
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'gross_amount' => 700,
            'period_from' => '2025-10-01',
            'period_to' => '2025-10-31',
            'paid_at' => '2025-10-31',
            'async' => true,
        ]);

        $response->assertStatus(202);
        $response->assertJson([
            'status' => 'queued',
        ]);

        Queue::assertPushed(ProcessSalaryCalculation::class, function ($job) use ($employee) {
            return $job->data['employee_id'] === $employee->id;
        });
    }

    public function test_salary_controller_executes_synchronously_by_default(): void
    {
        $user = $this->makeUserWithPermission('manage_salaries');
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear();
        $employee = Employee::create([
            'first_name' => 'نبيل',
            'last_name' => 'العياري',
            'default_salary' => 800,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/salaries', [
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'gross_amount' => 800,
            'period_from' => '2025-10-01',
            'period_to' => '2025-10-31',
            'paid_at' => '2025-10-31',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('salaries', [
            'employee_id' => $employee->id,
            'amount' => 800,
        ]);
    }

    public function test_process_bulk_enrollment_job_handles_bulk_enrollment(): void
    {
        $user = $this->makeUserWithPermission('manage_users');
        $year = $this->makeAcademicYear();

        $level = Level::create(['name' => 'السنة الثانية', 'code' => 'L2', 'order' => 2]);
        $section = Section::create(['level_id' => $level->id, 'name' => 'فوج أ', 'code' => 'S-A', 'capacity' => 25]);

        $data = [
            'academic_year_id' => $year->id,
            'section_id' => $section->id,
            'students' => [
                [
                    'first_name' => 'أحمد',
                    'last_name' => 'المثلوثي',
                    'father_name' => 'صالح',
                    'father_phone' => '98000001',
                ],
                [
                    'first_name' => 'مريم',
                    'last_name' => 'الغربي',
                    'mother_name' => 'فاطمة',
                    'mother_phone' => '98000002',
                ],
            ],
        ];

        $result = (new ProcessBulkEnrollment($data, $user->id))->handle();

        $this->assertEquals(2, $result['created']);
        $this->assertEmpty($result['skipped']);

        $this->assertDatabaseHas('students', ['first_name' => 'أحمد', 'last_name' => 'المثلوثي']);
        $this->assertDatabaseHas('students', ['first_name' => 'مريم', 'last_name' => 'الغربي']);
        $this->assertEquals(2, Enrollment::where('section_id', $section->id)->count());
    }

    public function test_roster_controller_dispatches_bulk_enrollment_when_async_flag_is_true(): void
    {
        Queue::fake([ProcessBulkEnrollment::class]);

        $user = $this->makeUserWithPermission('manage_users');
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear();
        $level = Level::create(['name' => 'السنة الثالثة', 'code' => 'L3', 'order' => 3]);
        $section = Section::create(['level_id' => $level->id, 'name' => 'فوج ب', 'code' => 'S-B', 'capacity' => 20]);

        $response = $this->postJson('/api/rosters/bulk', [
            'academic_year_id' => $year->id,
            'section_id' => $section->id,
            'async' => true,
            'students' => [
                [
                    'first_name' => 'يوسف',
                    'last_name' => 'المنستيري',
                ],
            ],
        ]);

        $response->assertStatus(202);
        $response->assertJson([
            'status' => 'queued',
        ]);

        Queue::assertPushed(ProcessBulkEnrollment::class, function ($job) use ($section) {
            return $job->data['section_id'] === $section->id;
        });
    }

    public function test_roster_controller_executes_bulk_enrollment_synchronously_by_default(): void
    {
        $user = $this->makeUserWithPermission('manage_users');
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear();
        $level = Level::create(['name' => 'السنة الرابعة', 'code' => 'L4', 'order' => 4]);
        $section = Section::create(['level_id' => $level->id, 'name' => 'فوج ج', 'code' => 'S-C', 'capacity' => 20]);

        $response = $this->postJson('/api/rosters/bulk', [
            'academic_year_id' => $year->id,
            'section_id' => $section->id,
            'students' => [
                [
                    'first_name' => 'هدى',
                    'last_name' => 'البوعزيزي',
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJson([
            'created' => 1,
            'skipped' => [],
        ]);

        $this->assertDatabaseHas('students', [
            'first_name' => 'هدى',
            'last_name' => 'البوعزيزي',
        ]);
    }

    private function makeUserWithPermission(string $permissionName): User
    {
        $user = $this->makeUser('clerk_' . uniqid());
        $user->update(['is_active' => true]);

        $permission = Permission::firstOrCreate(
            ['name' => $permissionName],
            ['display_name' => $permissionName, 'group' => 'system']
        );

        $user->role->permissions()->syncWithoutDetaching([$permission->id]);

        return $user;
    }
}
