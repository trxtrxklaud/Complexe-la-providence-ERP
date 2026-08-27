<?php

namespace Tests\Feature;

use App\Models\ManualStudentDebt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * سياسة انتهاء توكنات Sanctum وحماية تحصيل الديون القديمة من 401.
 *
 * سبب 401 التاريخي: SANCTUM_TOKEN_EXPIRATION كان 720 دقيقة (12 ساعة) —
 * توكن عمره أكثر من 12 ساعة يعيد 401 لكل طلب رغم صحة البيانات.
 * الإصلاح: 43200 دقيقة (30 يوماً) في .env — وهذا الاختبار يثبت السلوكين.
 */
class SanctumTokenExpirationTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(): User
    {
        $u = $this->makeUser('cashier');
        $u->update(['is_active' => true]);
        return $u;
    }

    private function issueBackdatedToken(User $user, int $hoursAgo): string
    {
        $access = $user->createToken('test_token');
        DB::table('personal_access_tokens')
            ->where('id', $access->accessToken->id)
            ->update(['created_at' => now()->subHours($hoursAgo)]);

        return $access->plainTextToken;
    }

    /** المستخدم المصادق صلاحيته 30 يوماً — لا 401. */
    public function test_token_within_30_day_expiration_does_not_return_401(): void
    {
        config(['sanctum.expiration' => 43200]);
        $user = $this->activeUser();
        $plain = $this->issueBackdatedToken($user, 25); // عمره 25 ساعة

        $this->withToken($plain)
            ->getJson('/api/user')
            ->assertStatus(200);
    }

    /** إثبات السبب الجذري: بالإعداد القديم (12 ساعة) نفس التوكن كان يعيد 401. */
    public function test_token_beyond_12h_expiration_returns_401_under_old_config(): void
    {
        config(['sanctum.expiration' => 720]);
        $user = $this->activeUser();
        $plain = $this->issueBackdatedToken($user, 25);

        $this->withToken($plain)
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    /** المستخدم غير المصادق ممنوع من التحصيل — 401 حصرياً. */
    public function test_unauthenticated_collect_is_rejected_with_401(): void
    {
        $this->postJson('/api/manual-debts/1/collect', [
            'amount' => 100,
            'method' => 'cash',
        ])->assertStatus(401);
    }
}
