<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_does_not_receive_financial_data(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('cashier', ['manage_payments', 'manage_students']));
        $this->makeAcademicYear();

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        // بيانات التلاميذ والمتخلَّد تبقى متاحة للقابض
        $this->assertArrayHasKey('total_students', $data);
        $this->assertArrayHasKey('outstanding_balance', $data);

        // الجرد النقدي محجوب تماماً على من لا يملك manage_treasury/view_reports
        $this->assertArrayNotHasKey('cash', $data);
        $this->assertArrayNotHasKey('treasury_balance', $data);
        $this->assertArrayNotHasKey('financial_summary', $data);
    }

    public function test_report_viewer_receives_financial_data(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('report_viewer', ['view_reports']));
        $this->makeAcademicYear();

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertArrayHasKey('cash', $data);
        $this->assertArrayHasKey('treasury_balance', $data);
        $this->assertArrayHasKey('financial_summary', $data);
    }

    public function test_treasury_manager_receives_financial_data(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('treasurer', ['manage_treasury']));
        $this->makeAcademicYear();

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        // manage_treasury وحدها تكفي — إثبات دلالة "أو"
        $this->assertArrayHasKey('cash', $data);
        $this->assertArrayHasKey('treasury_balance', $data);
        $this->assertArrayHasKey('financial_summary', $data);
    }

    public function test_admin_super_role_receives_financial_data(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('admin', []));
        $this->makeAcademicYear();

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        // الدور الخارق يتجاوز الفحص التفصيلي كما في CheckPermission
        $this->assertArrayHasKey('cash', $data);
        $this->assertArrayHasKey('treasury_balance', $data);
        $this->assertArrayHasKey('financial_summary', $data);
    }

    public function test_dashboard_returns_reconciled_gender_counts_excluding_duplicates_and_handling_unspecified_gender(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('admin', ['manage_students']));
        $year = $this->makeAcademicYear();

        $level = \App\Models\Level::firstOrCreate(['id' => 1], ['name' => 'السنة الأولى', 'code' => 'L1']);
        $sec1 = \App\Models\Section::firstOrCreate(['id' => 881], ['level_id' => $level->id, 'name' => 'قسم 1', 'code' => 'S881']);
        $sec2 = \App\Models\Section::firstOrCreate(['id' => 882], ['level_id' => $level->id, 'name' => 'قسم 2', 'code' => 'S882']);

        // Student 1: Male
        $maleStudent = \App\Models\Student::create([
            'student_code' => 'ST_MALE_1',
            'first_name' => 'أحمد',
            'last_name' => 'علي',
            'gender' => 'male',
        ]);
        \App\Models\Enrollment::create([
            'student_id' => $maleStudent->id,
            'academic_year_id' => $year->id,
            'level_id' => $level->id,
            'section_id' => $sec1->id,
            'status' => 'active',
            'enrollment_date' => now()->toDateString(),
        ]);

        // Student 2: Female with duplicate enrollment row in same active year
        $femaleStudent = \App\Models\Student::create([
            'student_code' => 'ST_FEMALE_1',
            'first_name' => 'مريم',
            'last_name' => 'بن طالب',
            'gender' => 'female',
        ]);
        \App\Models\Enrollment::create([
            'student_id' => $femaleStudent->id,
            'academic_year_id' => $year->id,
            'level_id' => $level->id,
            'section_id' => $sec1->id,
            'status' => 'active',
            'enrollment_date' => now()->toDateString(),
        ]);
        \App\Models\Enrollment::create([
            'student_id' => $femaleStudent->id,
            'academic_year_id' => $year->id,
            'level_id' => $level->id,
            'section_id' => $sec2->id,
            'status' => 'active',
            'enrollment_date' => now()->toDateString(),
        ]);

        // Student 3: Null/Unspecified gender with generic name
        $unspecifiedStudent = \App\Models\Student::create([
            'student_code' => 'ST_UNK_1',
            'first_name' => 'غير_معروف_123',
            'last_name' => 'مستورد',
            'gender' => null,
        ]);
        \App\Models\Enrollment::create([
            'student_id' => $unspecifiedStudent->id,
            'academic_year_id' => $year->id,
            'level_id' => $level->id,
            'section_id' => $sec1->id,
            'status' => 'active',
            'enrollment_date' => now()->toDateString(),
        ]);

        // Student 4: Null/Unspecified gender with male Arabic first name 'محمد'
        $unspecifiedArabicName = \App\Models\Student::create([
            'student_code' => 'ST_UNK_2',
            'first_name' => 'محمد',
            'last_name' => 'التونسي',
            'gender' => null,
        ]);
        \App\Models\Enrollment::create([
            'student_id' => $unspecifiedArabicName->id,
            'academic_year_id' => $year->id,
            'level_id' => $level->id,
            'section_id' => $sec1->id,
            'status' => 'active',
            'enrollment_date' => now()->toDateString(),
        ]);

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertEquals(4, $data['total_students']);
        $this->assertEquals(4, $data['total_active_students']);
        $this->assertEquals(1, $data['male_students_count']);
        $this->assertEquals(1, $data['total_males']);
        $this->assertEquals(1, $data['female_students_count']);
        $this->assertEquals(1, $data['total_females']);
        $this->assertEquals(2, $data['unknown_gender_count']);
        $this->assertEquals(2, $data['total_unspecified_gender']);

        // Reconciliation Assertion
        $this->assertEquals(
            $data['total_active_students'],
            $data['male_students_count'] + $data['female_students_count'] + $data['unknown_gender_count']
        );
    }

    /**
     * @param  array<int,string>  $permissions
     */
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
}
