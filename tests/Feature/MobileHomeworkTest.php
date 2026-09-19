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

class MobileHomeworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_store_and_list_homework(): void
    {
        [$teacher, $enrollment] = $this->makeTeacherWithSection(['manage_homework']);
        Sanctum::actingAs($teacher);

        $this->postJson("/api/mobile/teacher/sections/{$enrollment->section_id}/homework", [
            'subject' => 'math',
            'title' => 'exercises page 12',
            'description' => 'do 1-10',
            'publish' => true,
        ])->assertCreated();

        $response = $this->getJson("/api/mobile/teacher/sections/{$enrollment->section_id}/homework")->assertOk();

        $this->assertSame('exercises page 12', collect($response->json())->first()['title']);
    }

    public function test_teacher_cannot_access_foreign_section_homework(): void
    {
        [$teacher] = $this->makeTeacherWithSection(['manage_homework']);
        $foreign = $this->makeEnrollment();
        Sanctum::actingAs($teacher);

        $this->getJson("/api/mobile/teacher/sections/{$foreign->section_id}/homework")->assertForbidden();
        $this->postJson("/api/mobile/teacher/sections/{$foreign->section_id}/homework", [
            'title' => 'x',
        ])->assertForbidden();
    }

    public function test_teacher_without_permission_is_blocked(): void
    {
        [$teacher, $enrollment] = $this->makeTeacherWithSection();
        Sanctum::actingAs($teacher);

        $this->postJson("/api/mobile/teacher/sections/{$enrollment->section_id}/homework", [
            'title' => 'x',
        ])->assertForbidden();
    }

    public function test_parent_sees_only_published_homework_of_own_child(): void
    {
        [$teacher, $enrollment] = $this->makeTeacherWithSection(['manage_homework']);
        Sanctum::actingAs($teacher);

        $this->postJson("/api/mobile/teacher/sections/{$enrollment->section_id}/homework", [
            'title' => 'visible',
            'publish' => true,
        ])->assertCreated();

        $this->postJson("/api/mobile/teacher/sections/{$enrollment->section_id}/homework", [
            'title' => 'draft-hidden',
            'publish' => false,
        ])->assertCreated();

        [$parent, $student] = $this->makeParentWithChild('90002001', $enrollment);
        Sanctum::actingAs($parent);

        $response = $this->getJson("/api/mobile/parent/children/{$student->id}/homework")->assertOk();
        $titles = collect($response->json())->pluck('title')->all();

        $this->assertContains('visible', $titles);
        $this->assertNotContains('draft-hidden', $titles);
    }

    public function test_parent_cannot_see_other_child_homework(): void
    {
        [$parent] = $this->makeParentWithChild('90002002');
        $other = $this->makeEnrollment();
        $other->student->update(['guardian_phone' => '99888777']);
        Sanctum::actingAs($parent);

        $this->getJson("/api/mobile/parent/children/{$other->student_id}/homework")->assertForbidden();
    }

    /** @return array{0: User, 1: Enrollment} */
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
            'first_name' => 'teacher',
            'last_name' => 'test',
            'username' => 'teacher_'.$suffix,
            'email' => 'teacher_'.$suffix.'@test.local',
            'password' => 'secret123',
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $employee = Employee::create([
            'first_name' => 'teacher',
            'last_name' => 'test',
            'staff_type' => 'monthly_teacher',
            'salary_type' => 'monthly',
            'is_active' => true,
        ]);
        $employee->user_id = $teacher->id;
        $employee->save();

        $enrollment = $this->makeEnrollment();

        SectionTeacher::create([
            'section_id' => $enrollment->section_id,
            'employee_id' => $employee->id,
            'subject' => 'math',
        ]);

        return [$teacher->fresh(['role.permissions']), $enrollment];
    }

    /** @return array{0: User, 1: \App\Models\Student} */
    private function makeParentWithChild(string $phone, ?Enrollment $enrollment = null): array
    {
        $role = Role::firstOrCreate(['name' => 'parent'], ['display_name' => 'parent']);
        $perm = Permission::firstOrCreate(
            ['name' => 'view_own_children'],
            ['display_name' => 'view_own_children', 'group' => 'Mobile']
        );
        $role->permissions()->syncWithoutDetaching($perm->id);

        $parent = User::create([
            'first_name' => 'parent',
            'last_name' => $phone,
            'username' => 'parent_'.$phone,
            'email' => 'parent_'.$phone.'@parent.local',
            'phone' => $phone,
            'password' => 'secret123',
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $enrollment ??= $this->makeEnrollment();
        $enrollment->student->update(['guardian_phone' => $phone]);

        return [$parent->fresh(['role.permissions']), $enrollment->student->fresh()];
    }
}
