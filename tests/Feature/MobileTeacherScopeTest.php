<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SectionTeacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * يثبت أن المعلّم يرى أقسامه فقط (عبر section_teacher) ولا يصل قسماً ليس له (403).
 */
class MobileTeacherScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_sees_only_own_sections(): void
    {
        [$teacher, $ownEnrollment] = $this->makeTeacherWithSection();
        $foreignEnrollment = $this->makeEnrollment();

        Sanctum::actingAs($teacher);

        $response = $this->getJson('/api/mobile/teacher/sections')->assertOk();

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($ownEnrollment->section_id, $ids);
        $this->assertNotContains($foreignEnrollment->section_id, $ids);
    }

    public function test_teacher_can_read_own_section_roster(): void
    {
        [$teacher, $ownEnrollment] = $this->makeTeacherWithSection();
        Sanctum::actingAs($teacher);

        $response = $this->getJson("/api/mobile/teacher/sections/{$ownEnrollment->section_id}/students")
            ->assertOk();

        $this->assertContains(
            $ownEnrollment->id,
            collect($response->json())->pluck('enrollment_id')->all()
        );
    }

    public function test_teacher_cannot_access_foreign_section(): void
    {
        [$teacher] = $this->makeTeacherWithSection();
        $foreign = $this->makeEnrollment();

        Sanctum::actingAs($teacher);

        $this->getJson("/api/mobile/teacher/sections/{$foreign->section_id}/students")
            ->assertForbidden();
    }

    public function test_teacher_can_record_attendance_for_own_section(): void
    {
        [$teacher, $ownEnrollment] = $this->makeTeacherWithSection(['manage_attendance']);
        Sanctum::actingAs($teacher);

        $this->postJson("/api/mobile/teacher/sections/{$ownEnrollment->section_id}/attendance", [
            'date' => '2026-09-05',
            'entries' => [
                ['enrollment_id' => $ownEnrollment->id, 'status' => 'present'],
            ],
        ])->assertOk()->assertJsonPath('saved', 1);

        $this->assertDatabaseHas('attendances', [
            'enrollment_id' => $ownEnrollment->id,
            'date' => '2026-09-05',
            'status' => 'present',
        ]);
    }

    public function test_teacher_cannot_record_attendance_for_foreign_section(): void
    {
        [$teacher] = $this->makeTeacherWithSection(['manage_attendance']);
        $foreign = $this->makeEnrollment();

        Sanctum::actingAs($teacher);

        $this->postJson("/api/mobile/teacher/sections/{$foreign->section_id}/attendance", [
            'date' => '2026-09-05',
            'entries' => [
                ['enrollment_id' => $foreign->id, 'status' => 'present'],
            ],
        ])->assertForbidden();

        $this->assertDatabaseMissing('attendances', ['enrollment_id' => $foreign->id]);
    }

    /**
     * @param  array<int, string>  $extraPermissions
     * @return array{0: User, 1: Enrollment}
     */
    private function makeTeacherWithSection(array $extraPermissions = []): array
    {
        $role = Role::firstOrCreate(['name' => 'teacher'], ['display_name' => 'teacher']);

        foreach (array_merge(['view_own_sections'], $extraPermissions) as $name) {
            $perm = Permission::firstOrCreate(
                ['name' => $name],
                ['display_name' => $name, 'group' => 'Mobile']
            );
            $role->permissions()->syncWithoutDetaching($perm->id);
        }

        $suffix = uniqid();
        $teacher = User::create([
            'first_name' => 'معلّم',
            'last_name' => 'اختبار',
            'username' => 'teacher_'.$suffix,
            'email' => 'teacher_'.$suffix.'@test.local',
            'password' => 'secret123',
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $employee = Employee::create([
            'first_name' => 'معلّم',
            'last_name' => 'اختبار',
            'staff_type' => 'monthly_teacher',
            'salary_type' => 'monthly',
            'is_active' => true,
        ]);
        // user_id ليس ضمن fillable في موديل المنصّة — نضبطه مباشرةً دون تعديل الموديل.
        $employee->user_id = $teacher->id;
        $employee->save();

        $enrollment = $this->makeEnrollment();

        SectionTeacher::create([
            'section_id' => $enrollment->section_id,
            'employee_id' => $employee->id,
            'subject' => 'الرياضيات',
        ]);

        return [$teacher->fresh(['role.permissions']), $enrollment];
    }
}
