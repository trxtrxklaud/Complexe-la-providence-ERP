<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\ManualStudentDebt;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Permission;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * استخلاص الديون القديمة — واجهة التحصيل المخصصة.
 *
 * القاعدة المحورية: كل تحصيل يمرّ عبر CollectionService::collect() بنفس
 * المسار المالي (prior_year_debt/in)، مع حفظ الهدف الصريح
 * manual_student_debt_id، وبلا أي ارتباط بشهر دراسي.
 */
class OldDebtCollectionTest extends TestCase
{
    use RefreshDatabase;

    private $user;
    private AcademicYear $oldYear;
    private AcademicYear $currentYear;
    private Student $student;
    private Enrollment $currentEnrollment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->makeUser('cashier');
        $this->user->update(['is_active' => true]);
        $permission = Permission::firstOrCreate([
            'name' => 'manage_payments',
        ], [
            'display_name' => 'إدارة الاستخلاص',
            'group' => 'Finance',
        ]);
        $treasury = Permission::firstOrCreate([
            'name' => 'manage_treasury',
        ], [
            'display_name' => 'إدارة الخزينة',
            'group' => 'Finance',
        ]);
        // الضابط المالي يجمع بين الاستخلاص والخزينة (إدخال الأرصدة + التحصيل).
        $this->user->role->permissions()->syncWithoutDetaching([$permission->id, $treasury->id]);
        Sanctum::actingAs($this->user);

        $this->oldYear = AcademicYear::create([
            'name' => '2024-2025',
            'start_date' => '2024-09-01',
            'end_date' => '2025-06-30',
            'is_active' => false,
        ]);
        $this->currentYear = $this->makeAcademicYear('2025-2026');
        $this->student = Student::create([
            'student_code' => 'STU-ODC-'.uniqid(),
            'first_name' => 'أحمد',
            'last_name' => 'القديم',
            'gender' => 'male',
            'status' => 'active',
        ]);
        // تسجيل سابق (مصدر الجسر) + تسجيل حالي (سنة النقل).
        $this->makeEnrollment($this->oldYear, $this->student);
        $this->currentEnrollment = $this->makeEnrollment($this->currentYear, $this->student);
    }

    /** إنشاء دَين عبر المسار الرسمي وإرجاعه. */
    private function createDebt(string $debtType = 'tuition', float $amount = 1000): ManualStudentDebt
    {
        $res = $this->postJson('/api/manual-debts', [
            'student_id' => $this->student->id,
            'academic_year_id' => $this->currentYear->id,
            'original_year_label' => '2024/2025',
            'debt_type' => $debtType,
            'description' => "متخلّدات اختبار ($debtType)",
            'original_amount' => $amount,
        ])->assertCreated();

        return ManualStudentDebt::findOrFail($res->json('id'));
    }

    private function collectDebt(ManualStudentDebt $debt, float $amount, array $extra = [])
    {
        return $this->postJson("/api/manual-debts/{$debt->id}/collect", array_merge([
            'amount' => $amount,
            'payment_date' => '2025-10-01',
            'method' => 'cash',
        ], $extra));
    }

    public function test_old_debt_summary_lists_multiple_debts(): void
    {
        $this->createDebt('tuition', 1000);
        $this->createDebt('other', 500);

        $res = $this->getJson('/api/students/'.$this->student->id.'/old-debt-summary')
            ->assertOk();

        $this->assertCount(2, $res->json('items'));
        $this->assertEquals(1500, $res->json('totals.original_amount'));
        $this->assertEquals(1500, $res->json('totals.outstanding_amount'));
    }

    public function test_partial_collection_updates_outstanding_and_status(): void
    {
        $debt = $this->createDebt('tuition', 1000);

        $this->collectDebt($debt, 300)->assertCreated();

        $debt->refresh();
        $this->assertEqualsWithDelta(700, $debt->outstanding(), 0.001);
        $this->assertSame(ManualStudentDebt::STATUS_PARTIAL, $debt->status);
    }

    public function test_full_collection_marks_paid_and_blocks_more(): void
    {
        $debt = $this->createDebt('tuition', 1000);

        $this->collectDebt($debt, 1000)->assertCreated();
        $debt->refresh();
        $this->assertEqualsWithDelta(0, $debt->outstanding(), 0.001);
        $this->assertSame(ManualStudentDebt::STATUS_PAID, $debt->status);

        // الدفع بعد السداد الكامل مرفوض.
        $this->collectDebt($debt, 50)->assertStatus(422);
    }

    public function test_multiple_payments_on_different_dates(): void
    {
        $debt = $this->createDebt('tuition', 1000);

        $this->collectDebt($debt, 300)->assertCreated();
        $this->postJson("/api/manual-debts/{$debt->id}/collect", [
            'amount' => 200,
            'payment_date' => '2025-11-15',
            'method' => 'bank_transfer',
        ])->assertCreated();

        $rows = $this->getJson("/api/manual-debts/{$debt->id}/payments")
            ->assertOk()
            ->json('payments');

        $this->assertCount(2, $rows);
        $dates = array_column($rows, 'payment_date');
        $this->assertContains('2025-10-01', $dates);
        $this->assertContains('2025-11-15', $dates);
    }

    public function test_rejects_amount_over_outstanding(): void
    {
        $debt = $this->createDebt('tuition', 1000);

        $this->collectDebt($debt, 1001)->assertStatus(422);
        $debt->refresh();
        $this->assertEqualsWithDelta(1000, $debt->outstanding(), 0.001);
    }

    public function test_rejects_zero_and_negative_amounts(): void
    {
        $debt = $this->createDebt('tuition', 1000);

        $this->collectDebt($debt, 0)->assertStatus(422);
        $this->collectDebt($debt, -50)->assertStatus(422);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_saves_explicit_debt_target_on_allocation(): void
    {
        $debt = $this->createDebt('tuition', 1000);

        $this->collectDebt($debt, 300)->assertCreated();

        $alloc = PaymentAllocation::where('manual_student_debt_id', $debt->id)->first();
        $this->assertNotNull($alloc, 'يجب حفظ الهدف الصريح manual_student_debt_id');
        $this->assertEquals($debt->source_student_fee_id, $alloc->student_fee_id);
        $this->assertNull($alloc->opening_balance_id);
    }

    public function test_normal_monthly_payment_does_not_touch_old_debt(): void
    {
        $debt = $this->createDebt('tuition', 1000);
        $feeType = $this->makeFeeType('القسط الشهري', 400);

        $this->postJson('/api/payments/collect', [
            'student_id' => $this->student->id,
            'enrollment_id' => $this->currentEnrollment->id,
            'months' => ['2025-09'],
            'items' => [['fee_type_id' => $feeType->id, 'amount' => 400]],
            'payment_date' => '2025-09-20',
            'method' => 'cash',
        ])->assertCreated();

        $debt->refresh();
        $this->assertEqualsWithDelta(1000, $debt->outstanding(), 0.001);
        $this->assertSame(ManualStudentDebt::STATUS_PENDING, $debt->status);

        // تخصيص الشهر بلا هدف دين صريح.
        $this->assertDatabaseHas('payment_allocations', ['amount_allocated' => 400]);
        $this->assertDatabaseMissing('payment_allocations', [
            'amount_allocated' => 400,
            'manual_student_debt_id' => $debt->id,
        ]);
    }

    public function test_cancelling_payment_restores_outstanding(): void
    {
        $debt = $this->createDebt('tuition', 1000);

        $receipt = $this->collectDebt($debt, 300)->json('receipt');
        $paymentId = $receipt['payment_id'];

        $this->postJson("/api/payments/{$paymentId}/cancel", [
            'reason' => 'خطأ في التحصيل',
        ])->assertOk();

        $debt->refresh();
        $this->assertEqualsWithDelta(1000, $debt->outstanding(), 0.001);
        $this->assertSame(ManualStudentDebt::STATUS_PENDING, $debt->status);

        // السجل محفوظ (ملغى لا محذوف).
        $rows = $this->getJson("/api/manual-debts/{$debt->id}/payments")->json('payments');
        $cancelledRow = collect($rows)->firstWhere('payment_id', $paymentId);
        $this->assertNotNull($cancelledRow);
        $this->assertSame('cancelled', $cancelledRow['status']);
    }

    public function test_cancelled_payment_excluded_from_cash_in(): void
    {
        $debt = $this->createDebt('tuition', 1000);

        $this->collectDebt($debt, 300)->assertCreated();
        $this->assertEqualsWithDelta(
            300,
            (float) CashTransaction::whereNull('cancelled_at')->sum('amount'),
            0.001
        );

        $paymentId = Payment::query()->latest('id')->first()->getKey();
        $this->postJson("/api/payments/{$paymentId}/cancel", ['reason' => 'إلغاء للاختبار'])->assertOk();

        $this->assertEqualsWithDelta(
            0,
            (float) CashTransaction::whereNull('cancelled_at')->sum('amount'),
            0.001
        );
    }

    public function test_valid_collection_enters_cash_in_as_prior_year_debt(): void
    {
        $debt = $this->createDebt('tuition', 1000);

        $this->collectDebt($debt, 300)->assertCreated();

        $tx = CashTransaction::whereNull('cancelled_at')->firstOrFail();
        $this->assertSame(CashTransaction::CATEGORY_PRIOR_YEAR_DEBT, $tx->category);
        $this->assertSame(CashTransaction::DIRECTION_IN, $tx->direction);
        $this->assertEqualsWithDelta(300, (float) $tx->amount, 0.001);
    }

    public function test_old_debt_collection_not_in_current_income_or_net(): void
    {
        $debt = $this->createDebt('tuition', 1000);
        $this->collectDebt($debt, 300)->assertCreated();

        /** @var \App\Services\DashboardService $svc */
        $svc = app(\App\Services\DashboardService::class);
        $figures = null;
        $ref = new \ReflectionMethod($svc, 'cashFigures');
        $ref->setAccessible(true);
        $figures = $ref->invoke($svc, null, now()->toDateString());

        // يدخل cash_in/old_debt_collections ولا يدخل current_year_income/net_income.
        $this->assertEqualsWithDelta(300, $figures['old_debt_collections'], 0.001);
        $this->assertEqualsWithDelta(300, $figures['cash_in'], 0.001);
        $this->assertEqualsWithDelta(0, $figures['current_year_income'], 0.001);
        $this->assertEqualsWithDelta(0, $figures['net_income'], 0.001);
    }

    public function test_old_debt_payment_has_no_academic_months(): void
    {
        $debt = $this->createDebt('tuition', 1000);

        $this->collectDebt($debt, 300)->assertCreated();

        $paymentMonths = \App\Models\Payment::query()->latest('id')->value('months');
        $this->assertTrue(
            $paymentMonths === null || $paymentMonths === [] || $paymentMonths === '[]',
            'تحصيل الدين القديم يجب ألا يرتبط بشهر دراسي.'
        );
    }

    public function test_collect_requires_manage_payments_permission(): void
    {
        $debt = $this->createDebt('tuition', 1000); // أُنشئ بهوية الكاشير من setUp

        // مستخدم بلا صلاحية الاستخلاص:
        $viewer = $this->makeUser('viewer');
        $viewer->update(['is_active' => true]);
        Sanctum::actingAs($viewer);

        $this->collectDebt($debt, 100)->assertForbidden();
        $this->getJson('/api/manual-debts/'.$debt->id.'/payments')->assertForbidden();

        $debt->refresh();
        $this->assertEqualsWithDelta(1000, $debt->outstanding(), 0.001);
    }

    public function test_duplicate_individual_debt_same_type_is_rejected(): void
    {
        $this->createDebt('tuition', 1000);

        $this->postJson('/api/manual-debts', [
            'student_id' => $this->student->id,
            'academic_year_id' => $this->currentYear->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'tuition',
            'description' => 'نسخة مكررة',
            'original_amount' => 250,
        ])->assertStatus(422);

        $this->assertDatabaseCount('manual_student_debts', 1);
    }

    public function test_different_type_debt_is_allowed(): void
    {
        $this->createDebt('tuition', 1000);

        $this->postJson('/api/manual-debts', [
            'student_id' => $this->student->id,
            'academic_year_id' => $this->currentYear->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'registration',
            'description' => 'دين تسجيل مختلف',
            'original_amount' => 250,
        ])->assertCreated();

        $this->assertDatabaseCount('manual_student_debts', 2);
    }

    public function test_authenticated_collect_never_returns_401(): void
    {
        $debt = $this->createDebt('tuition', 1000);

        // مستخدم مصادق بصلاحية صحيحة: أي نتيجة تقبل ما عدا 401 (201 متوقع).
        $response = $this->collectDebt($debt, 300);
        $this->assertNotSame(401, $response->status(), 'مستخدم مصادق يجب ألا يصله 401');
        $response->assertCreated();
    }

    public function test_zero_total_collect_is_rejected_k1(): void
    {
        $debt = $this->createDebt('tuition', 500);

        // استدعاء مباشر للخدمة بمبلغ صفر (يتجاوز تحقق min:0.01 في HTTP) — K1.
        $payload = [
            'student_id' => $this->student->id,
            'enrollment_id' => $this->currentEnrollment->id,
            'months' => [],
            'items' => [],
            'club_items' => [],
            'prior_allocations' => [['manual_student_debt_id' => $debt->id, 'amount' => 0]],
            'payment_date' => now()->toDateString(),
            'method' => 'cash',
        ];

        $this->expectException(\InvalidArgumentException::class);
        app(\App\Services\CollectionService::class)->collect($payload, $this->user->id);
    }
}
