<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserPrivilegeEscalationTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_super_user_cannot_assign_super_role_when_creating_user(): void
    {
        $adminRole = $this->createRole('admin');
        $manager = $this->createManager();
        Sanctum::actingAs($manager);

        $this->postJson('/api/users', $this->userPayload($adminRole->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role_id'])
            ->assertJsonPath('errors.role_id.0', 'لا تملك صلاحية إسناد هذا الدور.');
    }

    public function test_user_cannot_change_own_role(): void
    {
        $manager = $this->createManager();
        $otherRole = $this->createRole('cashier');
        Sanctum::actingAs($manager);

        $this->putJson("/api/users/{$manager->id}", $this->userPayload($otherRole->id, $manager))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role_id'])
            ->assertJsonPath('errors.role_id.0', 'لا يمكنك تغيير دور حسابك الشخصي.');
    }

    public function test_user_cannot_disable_own_account(): void
    {
        $manager = $this->createManager();
        Sanctum::actingAs($manager);

        $payload = $this->userPayload($manager->role_id, $manager);
        $payload['is_active'] = false;

        $this->putJson("/api/users/{$manager->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active'])
            ->assertJsonPath('errors.is_active.0', 'لا يمكنك تعطيل حسابك الشخصي.');
    }

    public function test_super_user_can_assign_super_role(): void
    {
        $adminRole = $this->createRole('admin');
        $admin = $this->createUser($adminRole, 'admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/users', $this->userPayload($adminRole->id))
            ->assertCreated();
    }

    public function test_user_password_requires_ten_characters_with_letters_and_numbers(): void
    {
        $adminRole = $this->createRole('admin');
        $admin = $this->createUser($adminRole, 'admin');
        Sanctum::actingAs($admin);

        $payload = $this->userPayload($adminRole->id);
        $payload['password'] = 'short123';
        $payload['password_confirmation'] = 'short123';

        $this->postJson('/api/users', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_last_active_super_user_cannot_be_disabled(): void
    {
        $adminRole = $this->createRole('admin');
        $admin = $this->createUser($adminRole, 'admin');
        $manager = $this->createManager();
        Sanctum::actingAs($manager);

        $payload = $this->userPayload($manager->role_id, $admin);
        $payload['is_active'] = false;

        $this->putJson("/api/users/{$admin->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'لا يمكن حذف أو تعطيل آخر حساب مدير في النظام.');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_last_active_super_user_cannot_be_deleted(): void
    {
        $adminRole = $this->createRole('admin');
        $admin = $this->createUser($adminRole, 'admin');
        $manager = $this->createManager();
        Sanctum::actingAs($manager);

        $this->deleteJson("/api/users/{$admin->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'لا يمكن حذف أو تعطيل آخر حساب مدير في النظام.');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    private function createManager(): User
    {
        $role = $this->createRole('manager');
        $permission = Permission::create([
            'name' => 'manage_users',
            'display_name' => 'إدارة المستخدمين',
            'group' => 'Users',
        ]);
        $role->permissions()->attach($permission);

        return $this->createUser($role, 'manager');
    }

    private function createRole(string $name): Role
    {
        return Role::create([
            'name' => $name,
            'display_name' => $name,
        ]);
    }

    private function createUser(Role $role, string $prefix): User
    {
        return User::create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'username' => $prefix.'_user',
            'email' => $prefix.'@example.test',
            'password' => 'secret123',
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    private function userPayload(int $roleId, ?User $user = null): array
    {
        return [
            'first_name' => $user?->first_name ?? 'New',
            'last_name' => $user?->last_name ?? 'User',
            'username' => $user?->username ?? 'new_user',
            'email' => $user?->email ?? 'new@example.test',
            'phone' => null,
            'password' => $user ? null : 'Secret12345',
            'password_confirmation' => $user ? null : 'Secret12345',
            'role_id' => $roleId,
            'is_active' => true,
        ];
    }
}
