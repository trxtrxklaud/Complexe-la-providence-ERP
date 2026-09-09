<?php

namespace Tests\Feature;

use App\Mail\ParentVerificationMail;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ParentRegistrationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_fails_when_phone_not_linked_to_any_student(): void
    {
        $response = $this->postJson('/api/auth/parent/request-code', [
            'phone' => '22999888',
            'email' => 'newparent@gmail.com',
            'pseudo' => 'parent_chahd',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_fails_when_pseudo_already_taken(): void
    {
        $role = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'admin']);

        User::create([
            'first_name' => 'أحمد',
            'last_name' => 'المدير',
            'username' => 'taken_pseudo',
            'email' => 'other@test.local',
            'phone' => '22111222',
            'password' => 'secret',
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        Student::create([
            'first_name' => 'ياسين',
            'last_name' => 'العلوي',
            'guardian_first_name' => 'علي',
            'guardian_last_name' => 'العلوي',
            'guardian_phone' => '20054625',
        ]);

        $response = $this->postJson('/api/auth/parent/request-code', [
            'phone' => '20054625',
            'email' => 'parent.new@gmail.com',
            'pseudo' => 'taken_pseudo',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'اسم المستخدم (Pseudo) مستخدم بالفعل من قبل شخص آخر، يرجى اختيار اسم مختلف.',
            ]);
    }

    public function test_successfully_requests_code_and_dispatches_mail(): void
    {
        Mail::fake();

        Student::create([
            'first_name' => 'شهد',
            'last_name' => 'حسيني',
            'guardian_first_name' => 'عمر',
            'guardian_last_name' => 'حسيني',
            'guardian_phone' => '20054625',
        ]);

        $response = $this->postJson('/api/auth/parent/request-code', [
            'phone' => '20054625',
            'email' => 'parent.chahd@gmail.com',
            'pseudo' => 'abou_chahd',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'students_count' => 1,
            ])
            ->assertJsonStructure([
                'dev_code',
                'students_names',
            ]);

        Mail::assertSent(ParentVerificationMail::class, function ($mail) {
            return $mail->hasTo('parent.chahd@gmail.com') && $mail->pseudo === 'abou_chahd';
        });
    }

    public function test_fails_verification_with_invalid_code(): void
    {
        Student::create([
            'first_name' => 'شهد',
            'last_name' => 'حسيني',
            'guardian_phone' => '20054625',
        ]);

        $this->postJson('/api/auth/parent/request-code', [
            'phone' => '20054625',
            'email' => 'parent.chahd@gmail.com',
            'pseudo' => 'abou_chahd',
        ]);

        $response = $this->postJson('/api/auth/parent/verify-code', [
            'phone' => '20054625',
            'code' => '000000',
            'email' => 'parent.chahd@gmail.com',
            'pseudo' => 'abou_chahd',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'رمز التحقق غير صحيح أو انتهت صلاحيته.',
            ]);
    }

    public function test_full_activation_and_subsequent_login_with_pseudo_and_email(): void
    {
        Mail::fake();

        $parentRole = Role::firstOrCreate(['name' => 'parent'], ['display_name' => 'وليّ']);

        $student = Student::create([
            'first_name' => 'شهد',
            'last_name' => 'حسيني',
            'guardian_first_name' => 'عمر',
            'guardian_last_name' => 'حسيني',
            'guardian_phone' => '20054625',
        ]);

        // 1. طلب الرمز
        $reqResponse = $this->postJson('/api/auth/parent/request-code', [
            'phone' => '20054625',
            'email' => 'parent.chahd@gmail.com',
            'pseudo' => 'abou_chahd',
        ]);

        $reqResponse->assertStatus(200);
        $code = $reqResponse->json('dev_code');
        $this->assertNotEmpty($code);

        // 2. التحقق من الرمز وتفعيل الحساب
        $verifyResponse = $this->postJson('/api/auth/parent/verify-code', [
            'phone' => '20054625',
            'code' => $code,
            'email' => 'parent.chahd@gmail.com',
            'pseudo' => 'abou_chahd',
        ]);

        $verifyResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonPath('data.user.username', 'abou_chahd')
            ->assertJsonPath('data.user.email', 'parent.chahd@gmail.com')
            ->assertJsonPath('data.user.phone', '20054625')
            ->assertJsonPath('data.user.role', 'parent')
            ->assertJsonStructure([
                'data' => [
                    'access_token',
                    'token_type',
                    'user',
                ],
            ]);

        // تم ربط إيميل الولي بملف التلميذ
        $this->assertEquals('parent.chahd@gmail.com', $student->fresh()->guardian_email);

        // 3. الدخول اللاحق باستخدام اسم المستخدم (Pseudo) وكلمة السر (رقم الهاتف)
        $loginWithPseudo = $this->postJson('/api/auth/gmail-login', [
            'email' => 'abou_chahd', // pseudo
            'phone' => '20054625',   // password
        ]);

        $loginWithPseudo->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.username', 'abou_chahd');

        // 4. الدخول اللاحق باستخدام البريد (Gmail) وكلمة السر (رقم الهاتف)
        $loginWithEmail = $this->postJson('/api/auth/gmail-login', [
            'email' => 'parent.chahd@gmail.com',
            'phone' => '20054625',
        ]);

        $loginWithEmail->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.username', 'abou_chahd');
    }
}
