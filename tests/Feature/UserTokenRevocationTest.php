<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTokenRevocationTest extends TestCase
{
    use RefreshDatabase;

    private UserService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(UserService::class);
    }

    public function test_changing_password_revokes_tokens(): void
    {
        $user = $this->createUserWithToken();

        $this->service->updateUser($user, ['password' => 'new-secret-123']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_submitting_same_password_keeps_tokens(): void
    {
        $user = $this->createUserWithToken();

        $this->service->updateUser($user, ['password' => 'secret123']);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_disabling_user_revokes_tokens(): void
    {
        $user = $this->createUserWithToken();

        $this->service->updateUser($user, ['is_active' => false]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_updating_name_only_keeps_tokens(): void
    {
        $user = $this->createUserWithToken();

        $this->service->updateUser($user, ['first_name' => 'Updated']);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_deleting_user_revokes_tokens(): void
    {
        $user = $this->createUserWithToken();

        $this->service->deleteUser($user);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    private function createUserWithToken(): User
    {
        $role = Role::create([
            'name' => 'manager',
            'display_name' => 'Manager',
        ]);

        $user = User::create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'username' => 'manager_user',
            'email' => 'manager@example.test',
            'password' => 'secret123',
            'role_id' => $role->id,
            'is_active' => true,
        ]);
        $user->createToken('test-token');

        return $user;
    }
}
