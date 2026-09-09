<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GmailLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_fails_when_email_not_registered(): void
    {
        $response = $this->postJson('/api/auth/gmail-login', [
            'email' => 'test@gmail.com',
            'phone' => '20123456',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'هذا الإيميل غير مسجل في النظام',
            ]);
    }

    public function test_fails_when_phone_is_incorrect(): void
    {
        $role = Role::firstOrCreate(['name' => 'parent'], ['display_name' => 'ولي أمر']);

        User::create([
            'first_name' => 'أحمد',
            'last_name' => 'الولي',
            'username' => 'ahmed_parent',
            'email' => 'parent@gmail.com',
            'password' => 'secret123',
            'phone' => '20054625',
            'phone_password' => '20054625',
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/auth/gmail-login', [
            'email' => 'parent@gmail.com',
            'phone' => '99999999',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'كلمة السر غير صحيحة.',
            ]);
    }

    public function test_successful_login_with_gmail_and_phone(): void
    {
        $role = Role::firstOrCreate(['name' => 'parent'], ['display_name' => 'ولي أمر']);

        $user = User::create([
            'first_name' => 'أحمد',
            'last_name' => 'الولي',
            'username' => 'ahmed_parent',
            'email' => 'parent@gmail.com',
            'password' => 'secret123',
            'phone' => '20054625',
            'phone_password' => '20054625',
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/auth/gmail-login', [
            'email' => 'parent@gmail.com',
            'phone' => '20054625',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'تم تسجيل الدخول بنجاح',
            ])
            ->assertJsonPath('data.user.email', 'parent@gmail.com')
            ->assertJsonPath('data.user.role', 'parent')
            ->assertJsonStructure([
                'data' => [
                    'access_token',
                    'token_type',
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'phone',
                        'role',
                    ],
                ],
            ]);
    }

    public function test_admin_can_login_with_original_password(): void
    {
        $role = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'مدير']);

        User::create([
            'first_name' => 'مدير',
            'last_name' => 'المدرسة',
            'username' => 'school_admin',
            'email' => 'admin@laprovidence.ma',
            'password' => 'adminSecretPass!',
            'phone' => '22123456',
            'phone_password' => '22123456',
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        // دخول بكلمة السر الأصلية
        $response = $this->postJson('/api/auth/gmail-login', [
            'email' => 'admin@laprovidence.ma',
            'phone' => 'adminSecretPass!',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonPath('data.user.role', 'admin');
    }

    public function test_admin_can_login_with_phone_as_password(): void
    {
        $role = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'مدير']);

        User::create([
            'first_name' => 'مدير',
            'last_name' => 'المدرسة',
            'username' => 'school_admin',
            'email' => 'admin@laprovidence.ma',
            'password' => 'adminSecretPass!',
            'phone' => '22123456',
            'phone_password' => '22123456',
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        // دخول برقم الهاتف ككلمة سر
        $response = $this->postJson('/api/auth/gmail-login', [
            'email' => 'school_admin', // pseudo
            'phone' => '22123456',     // phone as password
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonPath('data.user.role', 'admin');
    }

    public function test_fallback_to_regular_phone_if_phone_password_is_null(): void
    {
        $role = Role::firstOrCreate(['name' => 'parent'], ['display_name' => 'ولي أمر']);

        User::create([
            'first_name' => 'فاطمة',
            'last_name' => 'الولي',
            'username' => 'fatma_parent',
            'email' => 'fatma@gmail.com',
            'password' => 'secret123',
            'phone' => '21609815',
            'phone_password' => null,
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/auth/gmail-login', [
            'email' => 'fatma@gmail.com',
            'phone' => '21609815',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }
}
