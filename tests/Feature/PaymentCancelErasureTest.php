<?php

namespace Tests\Feature;

use App\Models\CashTransaction;
use App\Models\Club;
use App\Models\ClubMonthlyFee;
use App\Models\FeeCategory;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\ClubService;
use App\Services\CollectionService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * قاعدة «إلغاء الوصل = مسح كلي للعملية»:
 * الوصل الملغى لا يسجَّل في الخزينة (تُلغى أسطر الدفتر) ولا في المتخلّد —
 * الرسوم المؤقتة التي أنشأها الاستخلاص نفسه تُحذف نهائياً، فيعود الشهر مفتوحاً
 * ويُعيد الموظف الاستخلاص عادياً، بينما يبقى سجل الوصل في «الوصولات الملغاة».
 */
class PaymentCancelErasureTest extends TestCase
{
    use RefreshDatabase;

    private function makeCashier(): User
    {
        $user = $this->makeUser('cashier');
        $user->update(['is_active' => true]);

        foreach (['manage_payments'] as $name) {
            Permission::firstOrCreate(
                ['name' => $name],
                ['display_name' => $name, 'group' => 'Test']
            );
            $user->role->permissions()->syncWithoutDetaching(Permission::where('name', $name)->first());
        }

        return $user->fresh(['role.permissions']);
    }

    /** لوحة المالية محجوبة عن القابض؛ يُفحص المتخلّد بحساب إدارة نشط. */
    private function dashboardPendingAmount(): int
    {
        $admin = $this->makeUser('admin');
        $admin->update(['is_active' => true]);
        Sanctum::actingAs($admin);

        return (int) $this->getJson('/api/dashboard')
            ->assertOk()
            ->json('data.financial_summary.pending_amount');
    }

    public function test_cancelled_collection_is_fully_erased_and_month_reopenable(): void
    {
        $user = $this->makeCashier();
        Sanctum::actingAs($user->fresh(['role']));

        $enrollment = $this->makeEnrollment();
        $feeType = $this->makeFeeType('القسط الشهري', 240);

        $receipt = app(CollectionService::class)->collect([
            'student_id' => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'months' => ['2025-09'],
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'items' => [['fee_type_id' => $feeType->id, 'amount' => 240]],
        ], $user->id);

        $paymentId = (int) $receipt['payment_id'];

        // قبل الإلغاء: الرسم مؤقَّت بدفعته، والدفتر مسجَّل، والشهر مدفوع.
        $this->assertDatabaseCount('student_fees', 1);
        $this->assertSame(1, CashTransaction::active()->count());

        // إلغاء موثّق بسببه.
        $this->postJson("/api/payments/{$paymentId}/cancel", ['reason' => 'خطأ في المبلغ'])
            ->assertOk();

        // بصمة العملية تُمحى: الرسم المؤقت وتوزيعاته اختفيا، وأسطر الدفتر أُلغيَت.
        $this->assertDatabaseCount('student_fees', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertSame(0, CashTransaction::active()->count());
        $this->assertSame(1, CashTransaction::whereNotNull('cancelled_at')->count());

        // سجل الوصل يبقى في «الوصولات الملغاة» مع السبب والمنفّذ.
        $payment = Payment::findOrFail($paymentId);
        $this->assertNotNull($payment->cancelled_at);
        $this->assertSame('خطأ في المبلغ', $payment->cancellation_reason);
        $this->assertSame($user->id, $payment->cancelled_by);

        // لا شيء في المتخلّد: يعيد الموظف العملية كانها لم تكن.
        $this->assertSame(0, $this->dashboardPendingAmount());
        Sanctum::actingAs($user->fresh(['role']));

        // إعادة الاستخلاص تعمل طبيعياً: شهر مفتوح، رسوم جديدة، دفتر جديد.
        $this->postJson('/api/payments/collect', [
            'student_id' => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'months' => ['2025-09'],
            'payment_date' => '2025-09-06',
            'method' => 'cash',
            'items' => [['fee_type_id' => $feeType->id, 'amount' => 240]],
        ])->assertCreated();

        $this->assertDatabaseCount('student_fees', 1);
        $this->assertSame(2, Payment::count());
        $this->assertSame(1, Payment::whereNull('cancelled_at')->count());
        $this->assertSame(1, CashTransaction::active()->count());
    }

    public function test_fee_shared_by_another_payment_survives_cancellation(): void
    {
        $user = $this->makeCashier();
        Sanctum::actingAs($user->fresh(['role']));

        $enrollment = $this->makeEnrollment();
        $fee = StudentFee::create([
            'enrollment_id' => $enrollment->id,
            'fee_plan_id' => null,
            'description' => 'قسط شهري',
            'amount_due' => 300,
            'due_date' => '2025-09-05',
            'status' => 'pending',
        ]);

        $service = app(PaymentService::class);
        $first = $service->recordPayment([
            'student_id' => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'amount' => 200,
            'payment_date' => '2025-09-10',
            'method' => 'cash',
            'allocations' => [['student_fee_id' => $fee->id, 'amount' => 200]],
        ], $user->id);

        $service->recordPayment([
            'student_id' => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'amount' => 100,
            'payment_date' => '2025-09-11',
            'method' => 'cash',
            'allocations' => [['student_fee_id' => $fee->id, 'amount' => 100]],
        ], $user->id);

        $this->assertSame('paid', $fee->fresh()->status);

        // إلغاء الدفعة الأولى فقط: الرسم يخصّ دفعة أخرى سارية فلا يُحذف،
        // ويعود جزئياً غير مدفوع تلقائياً (100/300).
        $this->postJson("/api/payments/{$first->id}/cancel", ['reason' => 'تدقيق داخلي'])
            ->assertOk();

        $this->assertDatabaseHas('student_fees', ['id' => $fee->id, 'status' => 'partial']);
        $this->assertSame(200, $this->dashboardPendingAmount());
    }

    public function test_club_fee_is_structural_and_returns_unpaid_on_cancellation(): void
    {
        $user = $this->makeCashier();
        Sanctum::actingAs($user->fresh(['role']));

        $enrollment = $this->makeEnrollment();

        $club = Club::create([
            'name' => 'نادي الحساب الذهني',
            'fee_category_id' => FeeCategory::firstOrCreate(
                ['code' => 'CLUB'],
                ['name' => 'معاليم النوادي', 'is_recurring' => true]
            )->id,
            'monthly_fee' => 10,
            'is_active' => true,
        ]);
        $club->sections()->attach($enrollment->section_id);

        // رسوم النادي بُنية شهرية مستقرة (رسم + رصيد شهر)، لا بصمة استخلاص.
        app(ClubService::class)->ensureFeesForEnrollment($enrollment, ['2025-09'], $user->id);

        $clubFee = ClubMonthlyFee::firstOrFail();
        $clubStudentFee = StudentFee::where('club_monthly_fee_id', $clubFee->id)->firstOrFail();

        $receipt = app(CollectionService::class)->collect([
            'student_id' => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'months' => ['2025-09'],
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'items' => [],
            'club_items' => [['club_monthly_fee_id' => $clubFee->id, 'amount' => 10]],
        ], $user->id);

        $this->assertSame(ClubMonthlyFee::STATUS_PAID, $clubFee->fresh()->status);

        // الإلغاء لا يمسّ البنية: رسم النادي يبقى ويعود غير مدفوع ليُعاد قبضه.
        $this->postJson("/api/payments/{$receipt['payment_id']}/cancel", ['reason' => 'خطأ في المبلغ'])
            ->assertOk();

        $this->assertDatabaseHas('student_fees', ['id' => $clubStudentFee->id]);
        $this->assertSame('pending', $clubStudentFee->fresh()->status);
        $this->assertSame(ClubMonthlyFee::STATUS_UNPAID, $clubFee->fresh()->status);
        $this->assertSame(0.0, (float) $clubFee->fresh()->amount_paid);
        $this->assertDatabaseCount('student_fees', 1);
        // توزيع الاستخلاص الملغى يبقى للمراجعة لكن لا يُحتسب في السداد.
        $this->assertSame(1, DB::table('payment_allocations')
            ->where('student_fee_id', $clubStudentFee->id)
            ->count());
    }
}
