<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserPermissionOverrideTest extends TestCase
{
    use RefreshDatabase;

    private function createPermission(string $name, string $displayName = 'صلاحية تجريبية'): Permission
    {
        return Permission::firstOrCreate(
            ['name' => $name],
            ['display_name' => $displayName, 'group' => 'Test']
        );
    }

    private function createRoleWithPermissions(string $name, array $permissionNames): Role
    {
        $role = Role::firstOrCreate(
            ['name' => $name],
            ['display_name' => $name]
        );

        $permissionIds = [];
        foreach ($permissionNames as $pName) {
            $permissionIds[] = $this->createPermission($pName)->id;
        }

        $role->permissions()->sync($permissionIds);

        return $role;
    }

    private function createUserWithRole(Role $role, string $username = 'test_user'): User
    {
        $suffix = uniqid();

        return User::create([
            'first_name' => 'محمد',
            'last_name'  => 'علي',
            'username'   => $username . '_' . $suffix,
            'email'      => $username . '_' . $suffix . '@test.local',
            'password'   => 'SecretPassword123!',
            'role_id'    => $role->id,
            'is_active'  => true,
        ]);
    }

    public function test_user_inherits_permissions_from_role(): void
    {
        $role = $this->createRoleWithPermissions('cashier', ['manage_payments', 'view_students']);
        $user = $this->createUserWithRole($role);

        $this->assertTrue($user->hasPermissionTo('manage_payments'));
        $this->assertTrue($user->hasPermissionTo('view_students'));
        $this->assertFalse($user->hasPermissionTo('manage_expenses'));

        $this->assertContains('manage_payments', $user->getEffectivePermissionNames());
        $this->assertContains('view_students', $user->getEffectivePermissionNames());
        $this->assertNotContains('manage_expenses', $user->getEffectivePermissionNames());
    }

    public function test_direct_grant_override_grants_permission_not_in_role(): void
    {
        $role = $this->createRoleWithPermissions('cashier', ['manage_payments']);
        $user = $this->createUserWithRole($role);
        $extraPerm = $this->createPermission('view_reports');

        $this->assertFalse($user->hasPermissionTo('view_reports'));

        UserPermissionOverride::create([
            'user_id'       => $user->id,
            'permission_id' => $extraPerm->id,
            'effect'        => UserPermissionOverride::EFFECT_GRANT,
        ]);

        $user->unsetRelation('permissionOverrides');

        $this->assertTrue($user->hasPermissionTo('view_reports'));
        $this->assertContains('view_reports', $user->getEffectivePermissionNames());
    }

    public function test_direct_deny_override_revokes_permission_present_in_role(): void
    {
        $role = $this->createRoleWithPermissions('cashier', ['manage_payments', 'view_students']);
        $user = $this->createUserWithRole($role);
        $permToRevoke = Permission::where('name', 'manage_payments')->first();

        $this->assertTrue($user->hasPermissionTo('manage_payments'));

        UserPermissionOverride::create([
            'user_id'       => $user->id,
            'permission_id' => $permToRevoke->id,
            'effect'        => UserPermissionOverride::EFFECT_DENY,
        ]);

        $user->unsetRelation('permissionOverrides');

        $this->assertFalse($user->hasPermissionTo('manage_payments'));
        $this->assertTrue($user->hasPermissionTo('view_students'));
        $this->assertNotContains('manage_payments', $user->getEffectivePermissionNames());
        $this->assertContains('view_students', $user->getEffectivePermissionNames());
    }

    public function test_direct_deny_override_revokes_permission_even_for_super_role(): void
    {
        config(['permissions.super_roles' => ['admin_super']]);

        $superRole = $this->createRoleWithPermissions('admin_super', []);
        $superUser = $this->createUserWithRole($superRole, 'super_user');
        $perm = $this->createPermission('manage_payments');

        $this->assertTrue($superUser->hasPermissionTo('manage_payments'));

        UserPermissionOverride::create([
            'user_id'       => $superUser->id,
            'permission_id' => $perm->id,
            'effect'        => UserPermissionOverride::EFFECT_DENY,
        ]);

        $superUser->unsetRelation('permissionOverrides');

        $this->assertFalse($superUser->hasPermissionTo('manage_payments'));
        $this->assertNotContains('manage_payments', $superUser->getEffectivePermissionNames());
    }

    public function test_updating_override_from_grant_to_deny(): void
    {
        $role = $this->createRoleWithPermissions('cashier', ['manage_payments']);
        $user = $this->createUserWithRole($role);
        $extraPerm = $this->createPermission('manage_expenses');

        $override = UserPermissionOverride::create([
            'user_id'       => $user->id,
            'permission_id' => $extraPerm->id,
            'effect'        => UserPermissionOverride::EFFECT_GRANT,
        ]);

        $this->assertTrue($user->hasPermissionTo('manage_expenses'));

        $override->update(['effect' => UserPermissionOverride::EFFECT_DENY]);
        $user->unsetRelation('permissionOverrides');

        $this->assertFalse($user->hasPermissionTo('manage_expenses'));
    }

    public function test_deleting_override_restores_role_permission(): void
    {
        $role = $this->createRoleWithPermissions('cashier', ['manage_payments']);
        $user = $this->createUserWithRole($role);
        $perm = Permission::where('name', 'manage_payments')->first();

        $override = UserPermissionOverride::create([
            'user_id'       => $user->id,
            'permission_id' => $perm->id,
            'effect'        => UserPermissionOverride::EFFECT_DENY,
        ]);

        $this->assertFalse($user->hasPermissionTo('manage_payments'));

        $override->delete();
        $user->unsetRelation('permissionOverrides');

        $this->assertTrue($user->hasPermissionTo('manage_payments'));
    }

    public function test_inactive_user_has_no_permissions(): void
    {
        $role = $this->createRoleWithPermissions('cashier', ['manage_payments']);
        $user = $this->createUserWithRole($role);
        $user->update(['is_active' => false]);

        $this->assertFalse($user->hasPermissionTo('manage_payments'));
        $this->assertEmpty($user->getEffectivePermissionNames());
    }

    public function test_unauthorized_user_cannot_access_permission_overrides_api(): void
    {
        $role = $this->createRoleWithPermissions('cashier', ['manage_payments']);
        $user = $this->createUserWithRole($role);
        $targetUser = $this->createUserWithRole($role, 'target');
        $perm = $this->createPermission('manage_expenses');

        Sanctum::actingAs($user);

        $this->getJson("/api/users/{$targetUser->id}/permission-overrides")
            ->assertForbidden();

        $this->postJson("/api/users/{$targetUser->id}/permission-overrides", [
            'permission_id' => $perm->id,
            'effect'        => 'grant',
        ])->assertForbidden();

        $this->putJson("/api/users/{$targetUser->id}/permission-overrides/{$perm->id}", [
            'effect' => 'grant',
        ])->assertForbidden();

        $this->deleteJson("/api/users/{$targetUser->id}/permission-overrides/{$perm->id}")
            ->assertForbidden();
    }

    public function test_manager_cannot_modify_own_permission_overrides(): void
    {
        $managerRole = $this->createRoleWithPermissions('admin_manager', ['manage_user_permissions']);
        $manager = $this->createUserWithRole($managerRole, 'manager');
        $perm = $this->createPermission('manage_salaries');

        Sanctum::actingAs($manager);

        $this->postJson("/api/users/{$manager->id}/permission-overrides", [
            'permission_id' => $perm->id,
            'effect'        => 'grant',
        ])->assertStatus(422)
          ->assertJsonPath('message', 'لا يمكنك تعديل صلاحيات حسابك الشخصي.');

        $this->putJson("/api/users/{$manager->id}/permission-overrides/{$perm->id}", [
            'effect' => 'grant',
        ])->assertStatus(422)
          ->assertJsonPath('message', 'لا يمكنك تعديل صلاحيات حسابك الشخصي.');

        $this->deleteJson("/api/users/{$manager->id}/permission-overrides/{$perm->id}")
            ->assertStatus(422)
          ->assertJsonPath('message', 'لا يمكنك تعديل صلاحيات حسابك الشخصي.');
    }

    public function test_manager_can_set_update_and_remove_permission_overrides(): void
    {
        $managerRole = $this->createRoleWithPermissions('admin_manager', ['manage_user_permissions']);
        $manager = $this->createUserWithRole($managerRole, 'manager');

        $cashierRole = $this->createRoleWithPermissions('cashier', ['manage_payments', 'view_students']);
        $cashier = $this->createUserWithRole($cashierRole, 'cashier_user');

        $expensePerm = $this->createPermission('manage_expenses');
        $paymentPerm = Permission::where('name', 'manage_payments')->first();

        Sanctum::actingAs($manager);

        // 1. Get initial breakdown
        $res = $this->getJson("/api/users/{$cashier->id}/permission-overrides")
            ->assertOk();
        $res->assertJsonPath('user.id', $cashier->id);

        // 2. Grant manage_expenses via POST
        $grantRes = $this->postJson("/api/users/{$cashier->id}/permission-overrides", [
            'permission_id' => $expensePerm->id,
            'effect'        => 'grant',
        ])->assertOk();

        $grantRes->assertJsonPath('override.effect', 'grant');
        $grantRes->assertJsonPath('override.created_by', $manager->id);
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id'       => $cashier->id,
            'permission_id' => $expensePerm->id,
            'effect'        => 'grant',
            'created_by'    => $manager->id,
        ]);

        // 3. Deny manage_payments via PUT
        $putRes = $this->putJson("/api/users/{$cashier->id}/permission-overrides/{$paymentPerm->id}", [
            'effect' => 'deny',
        ])->assertOk();
        $putRes->assertJsonPath('override.effect', 'deny');

        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id'       => $cashier->id,
            'permission_id' => $paymentPerm->id,
            'effect'        => 'deny',
            'created_by'    => $manager->id,
        ]);

        // 4. Update existing override via PUT and verify created_by does not change
        $secondManager = $this->createUserWithRole($managerRole, 'manager_two');
        Sanctum::actingAs($secondManager);

        $this->putJson("/api/users/{$cashier->id}/permission-overrides/{$paymentPerm->id}", [
            'effect' => 'grant',
        ])->assertOk();

        // created_by should still be $manager->id
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id'       => $cashier->id,
            'permission_id' => $paymentPerm->id,
            'effect'        => 'grant',
            'created_by'    => $manager->id,
        ]);

        // 5. Delete override for manage_payments
        $this->deleteJson("/api/users/{$cashier->id}/permission-overrides/{$paymentPerm->id}")
            ->assertOk();

        $this->assertDatabaseMissing('user_permission_overrides', [
            'user_id'       => $cashier->id,
            'permission_id' => $paymentPerm->id,
        ]);
    }

    public function test_auth_controller_login_and_user_endpoints_return_effective_permissions(): void
    {
        $role = $this->createRoleWithPermissions('cashier', ['manage_payments']);
        $user = $this->createUserWithRole($role, 'cashier_auth');
        $extraPerm = $this->createPermission('view_reports');

        UserPermissionOverride::create([
            'user_id'       => $user->id,
            'permission_id' => $extraPerm->id,
            'effect'        => 'grant',
        ]);

        // Test login
        $loginRes = $this->postJson('/api/login', [
            'email'    => $user->email,
            'password' => 'SecretPassword123!',
        ])->assertOk();

        $loginRes->assertJsonStructure([
            'access_token',
            'user' => ['id', 'email', 'effective_permissions'],
        ]);
        $this->assertContains('manage_payments', $loginRes->json('user.effective_permissions'));
        $this->assertContains('view_reports', $loginRes->json('user.effective_permissions'));

        // Test /api/user
        Sanctum::actingAs($user);
        $userRes = $this->getJson('/api/user')->assertOk();
        $this->assertContains('manage_payments', $userRes->json('effective_permissions'));
        $this->assertContains('view_reports', $userRes->json('effective_permissions'));
    }

    public function test_check_permission_middleware_uses_effective_permissions(): void
    {
        $role = $this->createRoleWithPermissions('cashier', ['manage_payments']);
        $user = $this->createUserWithRole($role, 'cashier_mw');
        $paymentPerm = Permission::where('name', 'manage_payments')->first();

        Sanctum::actingAs($user);

        // Can access payments
        $this->getJson('/api/payments')->assertOk();

        // Direct deny manage_payments
        UserPermissionOverride::create([
            'user_id'       => $user->id,
            'permission_id' => $paymentPerm->id,
            'effect'        => 'deny',
        ]);

        Sanctum::actingAs($user->fresh());

        // Access should now be 403 Forbidden
        $this->getJson('/api/payments')->assertForbidden();
    }

    public function test_validation_fails_on_invalid_permission_or_effect(): void
    {
        $managerRole = $this->createRoleWithPermissions('admin_manager', ['manage_user_permissions']);
        $manager = $this->createUserWithRole($managerRole, 'manager');
        $cashier = $this->createUserWithRole($managerRole, 'cashier_user');

        Sanctum::actingAs($manager);

        // Invalid permission ID on POST
        $this->postJson("/api/users/{$cashier->id}/permission-overrides", [
            'permission_id' => 99999,
            'effect'        => 'grant',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['permission_id']);

        // Invalid effect on POST
        $perm = $this->createPermission('manage_expenses');
        $this->postJson("/api/users/{$cashier->id}/permission-overrides", [
            'permission_id' => $perm->id,
            'effect'        => 'invalid_effect',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['effect']);

        // Invalid effect on PUT
        $this->putJson("/api/users/{$cashier->id}/permission-overrides/{$perm->id}", [
            'effect' => 'invalid_effect',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['effect']);
    }
}
