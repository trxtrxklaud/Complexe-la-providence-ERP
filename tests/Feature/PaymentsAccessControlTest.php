<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * عزل استخلاصات المستخدمين (كسر IDOR في GET /payments) + عقد الفهرس الذاتي
 * GET /payments/my-collections. يُثبِت أن المستخدم غير المميّز (manage_payments فقط، كالقابض)
 * لا يرى إلا دفعاته — لا عبر الفهرس العام، ولا عبر ?created_by=، ولا عبر my-collections —
 * بينما يحتفظ المميّز مالياً (view_reports / manage_treasury) برؤية الجميع. السياسة تُبنى
 * على الصلاحية لا على اسم الدور "admin".
 */
class PaymentsAccessControlTest extends TestCase
{
    use RefreshDatabase;

    /** يمنح المستخدم صلاحية بعينها ويُفعّله — نفس نمط عقد الصلاحيات. */
    private function grant(User $user, string $permission): User
    {
        $user->update(['is_active' => true]);
        $model = Permission::firstOrCreate(
            ['name' => $permission],
            ['display_name' => $permission, 'group' => 'Test']
        );
        $user->role->permissions()->syncWithoutDetaching($model->id);

        return $user->fresh(['role.permissions']);
    }

    private function pay(int $studentId, int $enrollmentId, int $createdBy, float $amount, string $date): Payment
    {
        return Payment::create([
            'student_id'    => $studentId,
            'enrollment_id' => $enrollmentId,
            'amount'        => $amount,
            'payment_date'  => $date,
            'method'        => 'cash',
            'created_by'    => $createdBy,
        ]);
    }

    /** ترسيم + قابضان A,B: دفعتان لـA وواحدة لـB. يعيد [A, B, enrollment]. */
    private function seedTwoCashiers(): array
    {
        $enrollment = $this->makeEnrollment();
        $a = $this->grant($this->makeUser('cashier_a'), 'manage_payments');
        $b = $this->grant($this->makeUser('cashier_b'), 'manage_payments');

        $this->pay($enrollment->student_id, $enrollment->id, $a->id, 240, '2025-09-10');
        $this->pay($enrollment->student_id, $enrollment->id, $a->id, 120, '2025-09-12');
        $this->pay($enrollment->student_id, $enrollment->id, $b->id, 300, '2025-09-15');

        return [$a, $b, $enrollment];
    }

    // (1) القابض A لا يرى دفعات القابض B عبر /payments (دون created_by).
    public function test_cashier_index_without_created_by_hides_other_users(): void
    {
        [$a, $b] = $this->seedTwoCashiers();
        Sanctum::actingAs($a);

        $res = $this->getJson('/api/payments?exclude_cancelled=1')->assertOk();

        $res->assertJsonPath('total', 2);
        $ids = collect($res->json('data'))->pluck('created_by.id')->unique()->values()->all();
        $this->assertSame([$a->id], $ids); // دفعات A فقط — لا يظهر B إطلاقاً
    }

    // (2) القابض A لا يستطيع التحايل عبر ?created_by=<B> — يبقى مقصوراً على دفعاته.
    public function test_cashier_cannot_bypass_isolation_with_created_by_param(): void
    {
        [$a, $b] = $this->seedTwoCashiers();
        Sanctum::actingAs($a);

        $res = $this->getJson("/api/payments?created_by={$b->id}")->assertOk();

        $res->assertJsonPath('total', 2); // دفعات A لا B — الوسيط مُتجاهَل
        foreach ($res->json('data') as $row) {
            $this->assertSame($a->id, $row['created_by']['id']);
        }
    }

    // (3) القابض A يرى دفعاته هو عبر /payments.
    public function test_cashier_index_returns_only_own_payments(): void
    {
        [$a] = $this->seedTwoCashiers();
        Sanctum::actingAs($a);

        $res = $this->getJson('/api/payments')->assertOk();

        $res->assertJsonPath('total', 2);
        foreach ($res->json('data') as $row) {
            $this->assertSame($a->id, $row['created_by']['id']);
        }
    }

    // (4) القابض A يرى دفعاته هو عبر /payments/my-collections.
    public function test_my_collections_returns_only_own(): void
    {
        [$a] = $this->seedTwoCashiers();
        Sanctum::actingAs($a);

        $res = $this->getJson('/api/payments/my-collections')->assertOk();

        $res->assertJsonPath('total', 2);
        foreach ($res->json('data') as $row) {
            $this->assertSame($a->id, $row['created_by']['id']);
        }
    }

    // (5) تمرير created_by=<B> إلى my-collections لا يغيّر النتيجة (لا يُقرأ إطلاقاً).
    public function test_my_collections_ignores_created_by_param(): void
    {
        [$a, $b] = $this->seedTwoCashiers();
        Sanctum::actingAs($a);

        $res = $this->getJson("/api/payments/my-collections?created_by={$b->id}")->assertOk();

        $res->assertJsonPath('total', 2);
        foreach ($res->json('data') as $row) {
            $this->assertSame($a->id, $row['created_by']['id']);
        }
    }

    // (6) my-collections يستبعد الملغاة عند exclude_cancelled=1، ويعدّها بدونه.
    public function test_my_collections_excludes_cancelled_when_requested(): void
    {
        $enrollment = $this->makeEnrollment();
        $a = $this->grant($this->makeUser('cashier_a'), 'manage_payments');

        $this->pay($enrollment->student_id, $enrollment->id, $a->id, 240, '2025-09-10');
        $cancelled = $this->pay($enrollment->student_id, $enrollment->id, $a->id, 120, '2025-09-12');
        $cancelled->cancelled_at = now();
        $cancelled->save();

        Sanctum::actingAs($a);

        $this->getJson('/api/payments/my-collections?exclude_cancelled=1')
            ->assertOk()->assertJsonPath('total', 1);

        $this->getJson('/api/payments/my-collections')
            ->assertOk()->assertJsonPath('total', 2);
    }

    // (7) مستخدم مميّز مالياً (view_reports — لا اسم الدور admin) يرى دفعات الجميع.
    public function test_privileged_user_sees_all_payments_via_index(): void
    {
        [$a, $b] = $this->seedTwoCashiers();

        // يحتاج manage_payments لتجاوز حارس المسار، وview_reports ليُعدّ مميّزاً في المتحكّم.
        // الدور "auditor" ليس super-role عمداً: الرؤية تأتي من الصلاحية لا من الاسم.
        $auditor = $this->grant($this->makeUser('auditor'), 'manage_payments');
        $auditor = $this->grant($auditor, 'view_reports');
        Sanctum::actingAs($auditor);

        $this->getJson('/api/payments')->assertOk()->assertJsonPath('total', 3);

        // ويحتفظ المميّز بمرشّح created_by الاختياري.
        $this->getJson("/api/payments?created_by={$b->id}")
            ->assertOk()->assertJsonPath('total', 1);
    }
}
