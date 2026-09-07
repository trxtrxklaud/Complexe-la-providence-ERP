<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobilePhoneAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_by_phone(): void
    {
        $role = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'admin']);

        $admin = User::create([
            'first_name' => 'مدير',
            'last_name' => 'النظام',
            'username' => 'admin_test_phone',
            'email' => 'admin_phone@test.local',
            'password' => 'secret123',
            'phone' => '0666123456',
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/auth/login-by-phone', [
            'phone' => '0666123456',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonStructure([
                'data' => [
                    'access_token',
                    'token_type',
                    'role',
                    'user',
                    'profile',
                ],
            ]);
    }

    public function test_teacher_login_by_phone(): void
    {
        $teacherRole = Role::firstOrCreate(['name' => 'teacher'], ['display_name' => 'معلّم']);

        $employee = Employee::create([
            'first_name' => 'محمد',
            'last_name' => 'الأستاذ',
            'phone' => '0666789012',
            'staff_type' => 'monthly_teacher',
            'salary_type' => 'monthly',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/auth/login-by-phone', [
            'phone' => '0666789012',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.role', 'teacher')
            ->assertJsonStructure([
                'data' => [
                    'access_token',
                    'token_type',
                    'role',
                    'user',
                    'profile',
                ],
            ]);
    }

    public function test_parent_login_by_phone_requires_otp(): void
    {
        $student = Student::create([
            'first_name' => 'سامي',
            'last_name' => 'الولي',
            'gender' => 'male',
            'guardian_phone' => '0666345678',
            'guardian_first_name' => 'أحمد',
            'guardian_last_name' => 'الولي',
        ]);

        // بلا رمز: مرفوض مع عَلَم otp_required — لا توكن بمعرفة الرقم وحده.
        $response = $this->postJson('/api/auth/login-by-phone', [
            'phone' => '0666345678',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('otp_required', true);

        // رمز خاطئ: مرفوض أيضاً.
        $this->postJson('/api/auth/login-by-phone', [
            'phone' => '0666345678',
            'otp_code' => '000000',
        ])->assertStatus(401)->assertJsonPath('otp_required', true);

        // لا يُنشأ مستخدم ولّي قبل التحقّق من الرمز.
        $this->assertDatabaseMissing('users', [
            'phone' => '66345678',
            'username' => 'parent_66345678',
        ]);
    }

    public function test_parent_login_by_phone_with_valid_otp_succeeds(): void
    {
        $student = Student::create([
            'first_name' => 'سامي',
            'last_name' => 'الولي',
            'gender' => 'male',
            'guardian_phone' => '0666345678',
            'guardian_first_name' => 'أحمد',
            'guardian_last_name' => 'الولي',
        ]);

        // وضع الإطلاق manual: request-otp يُرجع الرمز للإدارة.
        $otpResponse = $this->postJson('/api/mobile/parent/request-otp', [
            'phone' => '0666345678',
        ])->assertOk();

        $code = $otpResponse->json('dev_code');
        $this->assertNotNull($code);

        $response = $this->postJson('/api/auth/login-by-phone', [
            'phone' => '0666345678',
            'otp_code' => $code,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.role', 'parent')
            ->assertJsonPath('data.profile.children_count', 1)
            ->assertJsonStructure([
                'data' => [
                    'access_token',
                    'token_type',
                    'role',
                    'user',
                    'profile',
                ],
            ]);
    }

    public function test_parent_otp_code_is_single_use(): void
    {
        Student::create([
            'first_name' => 'سامي',
            'last_name' => 'الولي',
            'gender' => 'male',
            'guardian_phone' => '0666345678',
            'guardian_first_name' => 'أحمد',
            'guardian_last_name' => 'الولي',
        ]);

        $code = $this->postJson('/api/mobile/parent/request-otp', [
            'phone' => '0666345678',
        ])->json('dev_code');

        $this->postJson('/api/auth/login-by-phone', [
            'phone' => '0666345678',
            'otp_code' => $code,
        ])->assertOk();

        // إعادة استخدام الرمز نفسه بعد نجاح الدخول: مرفوضة.
        $this->postJson('/api/auth/login-by-phone', [
            'phone' => '0666345678',
            'otp_code' => $code,
        ])->assertStatus(401)->assertJsonPath('otp_required', true);
    }

    public function test_unknown_phone_returns_401(): void
    {
        // رقم غير مسجّل: نفس ردّ الوليّ بلا رمز — لا نكشف هل الرقم له أبناء.
        // (منع تعداد الأرقام، بأسلوب request-otp نفسه.)
        $response = $this->postJson('/api/auth/login-by-phone', [
            'phone' => '0666000000',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('otp_required', true);

        // وحتى مع رمز: لا وجود لتلميذ بهذا الرقم فلا دخول ولا تسريب معلومات.
        $this->postJson('/api/auth/login-by-phone', [
            'phone' => '0666000000',
            'otp_code' => '123456',
        ])->assertStatus(401)->assertJsonPath('otp_required', true);
    }

    public function test_invalid_phone_format_returns_422(): void
    {
        $response = $this->postJson('/api/auth/login-by-phone', [
            'phone' => '123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }
}
