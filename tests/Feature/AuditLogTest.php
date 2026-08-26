<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * تغطية مخصّصة للميزتين المُضافتين:
 *  - تغيير كلمة مرور المستخدم من قِبل المشرف (permission:manage_users).
 *  - نظام سجل العمليات (AuditService + مسار /audit-logs + وصل التحكّمات).
 *
 * كلها إضافية: لا تمسّ الدفتر النقدي ولا منطق الدفع/الاستخلاص.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * مستخدم فائق (دور admin يتجاوز الفحص التفصيلي) — يملك كل الصلاحيات.
     *
     * نضبط is_active صراحةً: makeUser لا يمرّرها، فيظلّ المتغيّر في الكائن
     * المُعاد null (قيمة العمود الافتراضية في القاعدة لا تُحمَّل بعد الإدراج)،
     * وSanctum::actingAs يستعمل هذا الكائن نفسه — وhasPermissionTo يرفض غير المُفعّل.
     */
    private function superAdmin(): User
    {
        $user = $this->makeUser('admin');
        $user->update(['is_active' => true]);

        return $user;
    }

    /** مدير غير فائق يملك صلاحية manage_users صراحةً (يُثبت البوابة لا التجاوز). */
    private function userManager(): User
    {
        $role = Role::create(['name' => 'user_manager', 'display_name' => 'مدير المستخدمين']);
        $permission = Permission::firstOrCreate(
            ['name' => 'manage_users'],
            ['display_name' => 'إدارة المستخدمين', 'group' => 'Users']
        );
        $role->permissions()->attach($permission);

        return User::create([
            'first_name' => 'ليلى',
            'last_name'  => 'بن عمر',
            'username'   => 'umanager_'.uniqid(),
            'email'      => 'umanager_'.uniqid().'@test.local',
            'password'   => 'secret123',
            'role_id'    => $role->id,
            'is_active'  => true,
        ]);
    }

    /** مستخدم عادي بلا أي صلاحية تفصيلية ولا دور فائق. */
    private function plainUser(): User
    {
        $role = Role::create(['name' => 'clerk', 'display_name' => 'كاتب']);

        return User::create([
            'first_name' => 'سامي',
            'last_name'  => 'التومي',
            'username'   => 'clerk_'.uniqid(),
            'email'      => 'clerk_'.uniqid().'@test.local',
            'password'   => 'secret123',
            'role_id'    => $role->id,
            'is_active'  => true,
        ]);
    }

    // ─── نظام سجل العمليات (AuditService) ────────────────────────────────

    public function test_audit_service_records_actor_ip_and_model(): void
    {
        $actor = $this->superAdmin();
        Sanctum::actingAs($actor);

        $student = Student::create([
            'student_code' => 'STU-'.uniqid(),
            'first_name'   => 'أحمد',
            'last_name'    => 'بن صالح',
            'gender'       => 'male',
            'status'       => 'active',
        ]);

        $log = AuditService::log('student.create', 'وصف عربي', $student, ['k' => 'v']);

        $this->assertNotNull($log);
        $this->assertDatabaseHas('audit_logs', [
            'action'     => 'student.create',
            'user_id'    => $actor->id,
            'user_name'  => 'منير الهمامي',
            'model_type' => 'Student',
            'model_id'   => $student->id,
        ]);
        $this->assertSame(['k' => 'v'], $log->fresh()->metadata);
    }

    public function test_audit_log_failure_never_throws(): void
    {
        // بلا مستخدم مصادَق ومع نموذج null: يجب أن تكتب سطراً دون أي استثناء.
        $log = AuditService::log('login', 'تسجيل الدخول إلى النظام');

        $this->assertNotNull($log);
        $this->assertNull($log->user_id);
        $this->assertNull($log->model_type);
    }

    public function test_login_is_audited(): void
    {
        $user = $this->makeUser('admin');

        $this->postJson('/api/login', [
            'email'    => $user->email,
            'password' => 'secret123',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action'  => 'login',
            'user_id' => $user->id,
        ]);
    }

    public function test_logout_is_audited(): void
    {
        $user = $this->makeUser('admin');
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/logout')
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action'  => 'logout',
            'user_id' => $user->id,
        ]);
    }

    public function test_user_creation_is_audited(): void
    {
        Sanctum::actingAs($admin = $this->superAdmin());

        $role = Role::create(['name' => 'teacher', 'display_name' => 'أستاذ']);

        $this->postJson('/api/users', [
            'first_name'            => 'وليد',
            'last_name'             => 'الساسي',
            'username'              => 'walid_'.uniqid(),
            'email'                 => 'walid_'.uniqid().'@test.local',
            'phone'                 => null,
            'password'              => 'Secret12345',
            'password_confirmation' => 'Secret12345',
            'role_id'               => $role->id,
            'is_active'             => true,
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action'     => 'user.create',
            'user_id'    => $admin->id,
            'model_type' => 'User',
        ]);
    }

    public function test_treasury_withdrawal_is_audited(): void
    {
        Sanctum::actingAs($admin = $this->superAdmin());

        $this->postJson('/api/treasury/withdrawals', [
            'amount'       => 50,
            'withdrawn_at' => '2026-01-15',
            'type'         => 'test',
            'note'         => 'اختبار سجل',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action'     => 'withdrawal.create',
            'user_id'    => $admin->id,
            'model_type' => 'TreasuryWithdrawal',
        ]);
    }

    // ─── مسار /audit-logs ───────────────────────────────────────────────

    public function test_audit_logs_endpoint_requires_manage_users(): void
    {
        Sanctum::actingAs($this->plainUser());

        $this->getJson('/api/audit-logs')->assertForbidden();
    }

    public function test_audit_logs_endpoint_lists_and_filters(): void
    {
        Sanctum::actingAs($this->userManager());

        AuditLog::create(['action' => 'login', 'description' => 'دخول', 'user_name' => 'أ']);
        AuditLog::create(['action' => 'payment.create', 'description' => 'دفعة', 'user_name' => 'ب']);
        AuditLog::create(['action' => 'payment.create', 'description' => 'دفعة', 'user_name' => 'ج']);

        $res = $this->getJson('/api/audit-logs')->assertOk();
        $res->assertJsonStructure(['data', 'current_page', 'last_page', 'total', 'per_page']);
        $this->assertSame(50, $res->json('per_page'));
        $this->assertSame(3, $res->json('total'));

        $filtered = $this->getJson('/api/audit-logs?action=payment.create')->assertOk();
        $this->assertSame(2, $filtered->json('total'));
        $this->assertSame('payment.create', $filtered->json('data.0.action'));
    }

    // ─── تغيير كلمة المرور من قِبل المشرف ────────────────────────────────

    public function test_manager_can_change_another_users_password(): void
    {
        Sanctum::actingAs($manager = $this->userManager());
        $target = $this->makeUser('teacher'); // كلمة المرور الأصلية 'secret123'

        $this->postJson("/api/users/{$target->id}/change-password", [
            'password'              => 'brandNew123',
            'password_confirmation' => 'brandNew123',
        ])->assertOk()->assertJsonPath('message', 'تم تغيير كلمة المرور بنجاح');

        $this->assertTrue(Hash::check('brandNew123', $target->fresh()->password));

        $this->assertDatabaseHas('audit_logs', [
            'action'     => 'user.password_changed',
            'user_id'    => $manager->id,
            'model_type' => 'User',
            'model_id'   => $target->id,
        ]);
    }

    public function test_change_password_revokes_targets_existing_tokens(): void
    {
        Sanctum::actingAs($this->userManager());
        $target = $this->makeUser('teacher');
        $target->createToken('old_session');

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->postJson("/api/users/{$target->id}/change-password", [
            'password'              => 'brandNew123',
            'password_confirmation' => 'brandNew123',
        ])->assertOk();

        $this->assertSame(0, $target->fresh()->tokens()->count());
    }

    public function test_change_password_enforces_minimum_length(): void
    {
        Sanctum::actingAs($this->userManager());
        $target = $this->makeUser('teacher');

        $this->postJson("/api/users/{$target->id}/change-password", [
            'password'              => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_change_password_requires_confirmation_match(): void
    {
        Sanctum::actingAs($this->userManager());
        $target = $this->makeUser('teacher');

        $this->postJson("/api/users/{$target->id}/change-password", [
            'password'              => 'brandNew123',
            'password_confirmation' => 'different123',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_change_password_requires_manage_users_permission(): void
    {
        Sanctum::actingAs($this->plainUser());
        $target = $this->makeUser('teacher');

        $this->postJson("/api/users/{$target->id}/change-password", [
            'password'              => 'brandNew123',
            'password_confirmation' => 'brandNew123',
        ])->assertForbidden();

        // كلمة المرور لم تتغيّر.
        $this->assertTrue(Hash::check('secret123', $target->fresh()->password));
    }
}
