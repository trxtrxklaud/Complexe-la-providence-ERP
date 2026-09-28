<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Level;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Section;
use App\Models\SectionTeacher;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherSectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $unauthorizedUser;
    private Employee $teacher1;
    private Employee $teacher2;
    private Section $section1;
    private Section $section2;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create Admin User with manage_users permission
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'مدير النظام']);
        $manageUsersPerm = Permission::firstOrCreate(
            ['name' => 'manage_users'],
            ['display_name' => 'إدارة المستخدمين', 'group' => 'Admin']
        );
        $adminRole->permissions()->syncWithoutDetaching([$manageUsersPerm->id]);

        $this->admin = User::create([
            'first_name' => 'المدير',
            'last_name' => 'العام',
            'username' => 'admin_test',
            'email' => 'admin_test@test.local',
            'password' => 'secret123',
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        // 2. Create unauthorized user
        $userRole = Role::firstOrCreate(['name' => 'guest'], ['display_name' => 'مستخدم عادي']);
        $this->unauthorizedUser = User::create([
            'first_name' => 'مستخدم',
            'last_name' => 'عادي',
            'username' => 'guest_test',
            'email' => 'guest_test@test.local',
            'password' => 'secret123',
            'role_id' => $userRole->id,
            'is_active' => true,
        ]);

        // 3. Create Level and Sections
        $level = Level::create(['name' => 'السنة الأولى', 'code' => 'L1', 'order' => 1]);
        $this->section1 = Section::create(['name' => 'أ', 'code' => 'S-1A', 'level_id' => $level->id]);
        $this->section2 = Section::create(['name' => 'ب', 'code' => 'S-1B', 'level_id' => $level->id]);

        // 4. Create Active Academic Year and Student Enrollment
        $year = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-09-15',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);

        $student = Student::create([
            'first_name' => 'علي',
            'last_name' => 'بن سالم',
            'student_code' => 'STU-001',
            'gender' => 'male',
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'section_id' => $this->section1->id,
            'level_id' => $level->id,
            'academic_year_id' => $year->id,
            'enrollment_date' => '2026-09-15',
            'status' => 'active',
        ]);

        // 5. Create Teachers (Employees)
        $this->teacher1 = Employee::create([
            'first_name' => 'صالح',
            'last_name' => 'الطرابلسي',
            'staff_type' => 'monthly_teacher',
            'phone' => '20111222',
            'email' => 'saleh@laprovidence.tn',
            'is_active' => true,
        ]);

        $this->teacher2 = Employee::create([
            'first_name' => 'فاطمة',
            'last_name' => 'المنصوري',
            'staff_type' => 'hourly_teacher',
            'phone' => '20333444',
            'email' => 'fatma@laprovidence.tn',
            'is_active' => true,
        ]);
    }

    public function test_get_teachers_list(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/teacher-sections/teachers')
            ->assertOk();

        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertCount(2, $data);

        $names = collect($data)->pluck('name')->all();
        $this->assertContains('صالح الطرابلسي', $names);
        $this->assertContains('فاطمة المنصوري', $names);
    }

    public function test_get_sections_list(): void
    {
        Sanctum::actingAs($this->admin);

        // Pre-assign teacher 1 to section 1
        SectionTeacher::create([
            'employee_id' => $this->teacher1->id,
            'section_id' => $this->section1->id,
            'subject' => 'الرياضيات',
        ]);

        $response = $this->getJson('/api/teacher-sections/sections')
            ->assertOk();

        $data = $response->json();
        $s1 = collect($data)->firstWhere('id', $this->section1->id);

        $this->assertNotNull($s1);
        $this->assertEquals('أ', $s1['name']);
        $this->assertEquals('السنة الأولى', $s1['level']['name']);
        $this->assertEquals(1, $s1['student_count']);
        $this->assertEquals(1, $s1['teacher_count']);
    }

    public function test_get_teacher_sections(): void
    {
        Sanctum::actingAs($this->admin);

        SectionTeacher::create([
            'employee_id' => $this->teacher1->id,
            'section_id' => $this->section1->id,
            'subject' => 'العربية',
        ]);

        $response = $this->getJson("/api/teacher-sections/teachers/{$this->teacher1->id}")
            ->assertOk();

        $response->assertJsonPath('teacher.id', $this->teacher1->id);
        $response->assertJsonPath('teacher.name', 'صالح الطرابلسي');
        $this->assertCount(1, $response->json('sections'));
        $this->assertEquals('أ', $response->json('sections.0.section.name'));
        $this->assertEquals('العربية', $response->json('sections.0.subject'));
    }

    public function test_assign_section_to_teacher(): void
    {
        Sanctum::actingAs($this->admin);

        // First teacher assigned
        SectionTeacher::create([
            'employee_id' => $this->teacher1->id,
            'section_id' => $this->section1->id,
            'subject' => 'العربية',
        ]);

        // Assign second teacher to same section (different subject)
        $response = $this->postJson('/api/teacher-sections/assign', [
            'employee_id' => $this->teacher2->id,
            'section_id' => $this->section1->id,
            'subject' => 'الفرنسية',
        ])->assertCreated();

        $this->assertTrue($response->json('success'));
        $this->assertEquals('الفرنسية', $response->json('data.subject'));

        // Check warning info about other teachers in section
        $otherTeachers = $response->json('other_teachers');
        $this->assertCount(1, $otherTeachers);
        $this->assertEquals('صالح الطرابلسي', $otherTeachers[0]['name']);
        $this->assertEquals('العربية', $otherTeachers[0]['subject']);

        // Assert in DB
        $this->assertDatabaseHas('section_teacher', [
            'employee_id' => $this->teacher2->id,
            'section_id' => $this->section1->id,
            'subject' => 'الفرنسية',
        ]);
    }

    public function test_assign_duplicate_section_to_same_teacher_is_rejected(): void
    {
        Sanctum::actingAs($this->admin);

        SectionTeacher::create([
            'employee_id' => $this->teacher1->id,
            'section_id' => $this->section1->id,
            'subject' => 'العربية',
        ]);

        $response = $this->postJson('/api/teacher-sections/assign', [
            'employee_id' => $this->teacher1->id,
            'section_id' => $this->section1->id,
            'subject' => 'مادة أخرى',
        ])->assertStatus(422);

        $response->assertJsonPath('error', 'هذا القسم مسند بالفعل لهذا المعلم');
    }

    public function test_remove_section_from_teacher(): void
    {
        Sanctum::actingAs($this->admin);

        $st = SectionTeacher::create([
            'employee_id' => $this->teacher1->id,
            'section_id' => $this->section1->id,
            'subject' => 'العربية',
        ]);

        $response = $this->deleteJson('/api/teacher-sections/remove', [
            'employee_id' => $this->teacher1->id,
            'section_id' => $this->section1->id,
        ])->assertOk();

        $this->assertTrue($response->json('success'));
        $this->assertDatabaseMissing('section_teacher', ['id' => $st->id]);
    }

    public function test_remove_nonexistent_assignment_returns_404(): void
    {
        Sanctum::actingAs($this->admin);

        $this->deleteJson('/api/teacher-sections/remove', [
            'employee_id' => $this->teacher1->id,
            'section_id' => $this->section2->id,
        ])->assertStatus(404);
    }

    public function test_unauthorized_user_cannot_manage_teacher_sections(): void
    {
        Sanctum::actingAs($this->unauthorizedUser);

        $this->getJson('/api/teacher-sections/teachers')->assertForbidden();
        $this->getJson('/api/teacher-sections/sections')->assertForbidden();
        $this->postJson('/api/teacher-sections/assign', [
            'employee_id' => $this->teacher1->id,
            'section_id' => $this->section1->id,
        ])->assertForbidden();
    }

    public function test_delete_teacher_sections_without_replacement(): void
    {
        Sanctum::actingAs($this->admin);

        SectionTeacher::create([
            'employee_id' => $this->teacher1->id,
            'section_id' => $this->section1->id,
            'subject' => 'العربية',
        ]);
        SectionTeacher::create([
            'employee_id' => $this->teacher1->id,
            'section_id' => $this->section2->id,
            'subject' => 'الرياضيات',
        ]);

        $response = $this->deleteJson("/api/teacher-sections/teachers/{$this->teacher1->id}")
            ->assertOk();

        $this->assertTrue($response->json('success'));
        $this->assertEquals(0, SectionTeacher::where('employee_id', $this->teacher1->id)->count());
    }

    public function test_delete_teacher_sections_with_replacement(): void
    {
        Sanctum::actingAs($this->admin);

        SectionTeacher::create([
            'employee_id' => $this->teacher1->id,
            'section_id' => $this->section1->id,
            'subject' => 'العربية',
        ]);
        SectionTeacher::create([
            'employee_id' => $this->teacher1->id,
            'section_id' => $this->section2->id,
            'subject' => 'الرياضيات',
        ]);

        $response = $this->deleteJson("/api/teacher-sections/teachers/{$this->teacher1->id}", [
            'replacement_employee_id' => $this->teacher2->id,
        ])->assertOk();

        $this->assertTrue($response->json('success'));
        $this->assertEquals(2, $response->json('transferred_count'));

        // Original teacher has no sections
        $this->assertEquals(0, SectionTeacher::where('employee_id', $this->teacher1->id)->count());

        // Replacement teacher received both sections
        $this->assertDatabaseHas('section_teacher', [
            'employee_id' => $this->teacher2->id,
            'section_id' => $this->section1->id,
            'subject' => 'العربية',
        ]);
        $this->assertDatabaseHas('section_teacher', [
            'employee_id' => $this->teacher2->id,
            'section_id' => $this->section2->id,
            'subject' => 'الرياضيات',
        ]);
    }
}
