<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileAdminRoleGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_cannot_reach_admin_endpoints(): void
    {
        [$parent] = $this->makeParentWithChild('90001001');
        Sanctum::actingAs($parent);

        $this->getJson('/api/mobile/admin/sections')->assertForbidden();
        $this->getJson('/api/mobile/admin/ledgers')->assertForbidden();
        $this->getJson('/api/mobile/admin/grades')->assertForbidden();
    }

    public function test_teacher_cannot_reach_admin_endpoints(): void
    {
        [$teacher] = $this->makeTeacherWithSection();
        Sanctum::actingAs($teacher);

        $this->getJson('/api/mobile/admin/sections')->assertForbidden();
        $this->getJson('/api/mobile/admin/ledgers')->assertForbidden();
    }

    public function test_admin_can_reach_admin_endpoints(): void
    {
        $admin = $this->makeUser('admin');
        $admin->update(['is_active' => true]);
        Sanctum::actingAs($admin->fresh(['role']));

        $this->getJson('/api/mobile/admin/sections')->assertOk();
    }

    /** @return array{0: User, 1: \App\Models\Student, 2: \App\Models\Enrollment} */
    private function makeParentWithChild(string $phone): array
    {
        $role = Role::firstOrCreate(['name' => 'parent'], ['display_name' => 'parent']);
        $perm = \App\Models\Permission::firstOrCreate(
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

        $enrollment = $this->makeEnrollment();
        $enrollment->student->update(['guardian_phone' => $phone]);

        return [$parent->fresh(['role.permissions']), $enrollment->student->fresh(), $enrollment];
    }

    /** @return array{0: User, 1: \App\Models\Enrollment} */
    private function makeTeacherWithSection(): array
    {
        $role = Role::firstOrCreate(['name' => 'teacher'], ['display_name' => 'teacher']);
        $perm = \App\Models\Permission::firstOrCreate(
            ['name' => 'view_own_sections'],
            ['display_name' => 'view_own_sections', 'group' => 'Mobile']
        );
        $role->permissions()->syncWithoutDetaching($perm->id);

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

        $employee = \App\Models\Employee::create([
            'first_name' => 'teacher',
            'last_name' => 'test',
            'staff_type' => 'monthly_teacher',
            'salary_type' => 'monthly',
            'is_active' => true,
        ]);
        $employee->user_id = $teacher->id;
        $employee->save();

        $enrollment = $this->makeEnrollment();

        \App\Models\SectionTeacher::create([
            'section_id' => $enrollment->section_id,
            'employee_id' => $employee->id,
            'subject' => 'math',
        ]);

        return [$teacher->fresh(['role.permissions']), $enrollment];
    }
}
