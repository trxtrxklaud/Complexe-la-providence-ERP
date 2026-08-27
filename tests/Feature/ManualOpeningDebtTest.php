<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\ManualStudentDebt;
use App\Models\PaymentAllocation;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * الأرصدة الافتتاحية اليدوية: ديون التلاميذ القديمة ومستحقات الإطارات.
 *
 * القاعدة المحورية: إدخال الدَّين لا يحرّك مالاً، والتحصيل يمرّ حصراً عبر
 * CollectionService::collect() → LedgerService::recordPayment() → الدفتر
 * النقدي المركزي، ويُصنَّف من اختلاف سنة تسجيل الرسم عن سنة الدفعة:
 *  - رسم جسر تحت سنة سابقة → prior_year_debt (متخلّدات) لا يدخل الدخل الصافي.
 *  - رسم السنة الحالية → monthly_fee (مدخول).
 */
class ManualOpeningDebtTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser()
    {
        $user = $this->makeUser('admin');
        $user->update(['is_active' => true]);

        return $user;
    }

    /**
     * تلميذ بتسجيلين: سنة سابقة (مصدر الدَّين) وسنة حالية (النقل إليها).
     *
     * @return array{0: Student, 1: int, 2: int} [student, oldYearId, currentYearId]
     */
    private function studentWithTwoYears(): array
    {
        $oldYear = AcademicYear::create([
            'name' => '2024-2025',
            'start_date' => '2024-09-01',
            'end_date' => '2025-06-30',
            'is_active' => false,
        ]);
        $currentYear = $this->makeAcademicYear('2025-2026');
        $student = Student::create([
            'student_code' => 'STU-OLD-'.uniqid(),
            'first_name' => 'أحمد',
            'last_name' => 'بن صالح',
            'gender' => 'male',
            'status' => 'active',
        ]);
        $this->makeEnrollment($oldYear, $student);
        $currentEnrollment = $this->makeEnrollment($currentYear, $student);

        return [$student, $oldYear->id, $currentYear->id, $currentEnrollment];
    }

    /** إدخال دَين قديم يدوياً عبر المسار الرسمي (manage_treasury). */
    private function enterManualDebt(int $studentId, int $yearId, float $amount = 10000): ManualStudentDebt
    {
        $response = $this->postJson('/api/manual-debts', [
            'student_id' => $studentId,
            'academic_year_id' => $yearId,
            'original_year_label' => '2024/2025',
            'debt_type' => 'tuition',
            'description' => 'متخلّدات السنة السابقة',
            'original_amount' => $amount,
        ]);

        $response->assertCreated();

        return ManualStudentDebt::findOrFail($response->json('id'));
    }

    private function collectPayload(int $studentId, int $enrollmentId, array $overrides = []): array
    {
        return array_merge([
            'student_id' => $studentId,
            'enrollment_id' => $enrollmentId,
            'payment_date' => '2025-09-05',
            'method' => 'cash',
        ], $overrides);
    }

    public function test_manual_debt_collection_posts_prior_year_debt_not_current_revenue(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$student, $oldYearId, $currentYearId, $enrollment] = $this->studentWithTwoYears();
        $debt = $this->enterManualDebt($student->id, $currentYearId, 10000);

        $this->postJson('/api/payments/collect', $this->collectPayload($student->id, $enrollment->id, [
            'prior_allocations' => [['manual_student_debt_id' => $debt->id, 'amount' => 3000]],
        ]))->assertCreated();

        // الخزينة: سطر واحد في الدفتر النقدي — قبض متخلّدات، لا مدخول حالي.
        $this->assertDatabaseCount('cash_transactions', 1);
        $this->assertDatabaseHas('cash_transactions', [
            'category' => CashTransaction::CATEGORY_PRIOR_YEAR_DEBT,
            'direction' => CashTransaction::DIRECTION_IN,
            'amount' => 3000,
        ]);
        $this->assertDatabaseMissing('cash_transactions', [
            'category' => CashTransaction::CATEGORY_MONTHLY_FEE,
        ]);

        // المتبقّي يُشتقّ من التوزيعات الفعلية: 10000 − 3000.
        $debt->refresh();
        $this->assertSame(7000.0, $debt->outstanding());
        $this->assertSame(ManualStudentDebt::STATUS_PARTIAL, $debt->status);

        // التوزيع على الرسم الجسر (سنة سابقة) — لا رسوم جديدة في السنة الحالية.
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertSame($debt->source_student_fee_id, PaymentAllocation::first()->student_fee_id);
        $this->assertSame($oldYearId, $debt->sourceStudentFee->enrollment->academic_year_id);
    }

    public function test_current_year_payment_remains_monthly_fee(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$student, , $currentYearId, $enrollment] = $this->studentWithTwoYears();
        $feeType = $this->makeFeeType('القسط الشهري', 4000);

        $this->postJson('/api/payments/collect', $this->collectPayload($student->id, $enrollment->id, [
            'months' => ['2025-09'],
            'items' => [['fee_type_id' => $feeType->id, 'amount' => 4000]],
        ]))->assertCreated();

        $this->assertDatabaseCount('cash_transactions', 1);
        $this->assertDatabaseHas('cash_transactions', [
            'category' => CashTransaction::CATEGORY_MONTHLY_FEE,
            'direction' => CashTransaction::DIRECTION_IN,
            'amount' => 4000,
        ]);
        $this->assertDatabaseMissing('cash_transactions', [
            'category' => CashTransaction::CATEGORY_PRIOR_YEAR_DEBT,
        ]);
    }

    public function test_mixed_session_totals_split_between_current_and_prior(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$student, , $currentYearId, $enrollment] = $this->studentWithTwoYears();
        $debt = $this->enterManualDebt($student->id, $currentYearId, 10000);
        $feeType = $this->makeFeeType('القسط الشهري', 4000);

        // نفس الجلسة: 4000 مدخول حالي + 3000 متخلّدات في وصل واحد.
        $this->postJson('/api/payments/collect', $this->collectPayload($student->id, $enrollment->id, [
            'months' => ['2025-09'],
            'items' => [['fee_type_id' => $feeType->id, 'amount' => 4000]],
            'prior_allocations' => [['manual_student_debt_id' => $debt->id, 'amount' => 3000]],
        ]))->assertCreated();

        // الدفتر هو مصدر الحقيقة الوحيد: النقد الكلي = 7000.
        $this->assertSame(7000.0, round((float) CashTransaction::whereNull('cancelled_at')->sum('amount'), 2));

        $totals = CashTransaction::whereNull('cancelled_at')
            ->select('category', DB::raw('SUM(amount) as total'))
            ->groupBy('category')
            ->pluck('total', 'category');

        $this->assertSame(4000.0, round((float) $totals[CashTransaction::CATEGORY_MONTHLY_FEE], 2));
        $this->assertSame(3000.0, round((float) $totals[CashTransaction::CATEGORY_PRIOR_YEAR_DEBT], 2));
    }

    public function test_idempotency_key_prevents_duplicate_collection(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$student, , $currentYearId, $enrollment] = $this->studentWithTwoYears();
        $debt = $this->enterManualDebt($student->id, $currentYearId, 10000);
        $payload = $this->collectPayload($student->id, $enrollment->id, [
            'prior_allocations' => [['manual_student_debt_id' => $debt->id, 'amount' => 3000]],
        ]);
        $payload['idempotency_key'] = 'manual-debt-key-'.uniqid();

        $this->postJson('/api/payments/collect', $payload)->assertCreated();
        $this->postJson('/api/payments/collect', $payload)->assertCreated();

        // مفتاح واحد → وصل واحد → توزيع واحد → سطر خزينة واحد.
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseCount('cash_transactions', 1);

        $debt->refresh();
        $this->assertSame(7000.0, $debt->outstanding());
    }

    public function test_cannot_collect_more_than_outstanding(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$student, , $currentYearId, $enrollment] = $this->studentWithTwoYears();
        $debt = $this->enterManualDebt($student->id, $currentYearId, 10000);

        $this->postJson('/api/payments/collect', $this->collectPayload($student->id, $enrollment->id, [
            'prior_allocations' => [['manual_student_debt_id' => $debt->id, 'amount' => 12000]],
        ]))->assertStatus(422);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('cash_transactions', 0);

        // تحصيل جزئي ثم تجاوز المتبقّي الجديد (7000).
        $this->postJson('/api/payments/collect', $this->collectPayload($student->id, $enrollment->id, [
            'prior_allocations' => [['manual_student_debt_id' => $debt->id, 'amount' => 3000]],
        ]))->assertCreated();

        $this->postJson('/api/payments/collect', $this->collectPayload($student->id, $enrollment->id, [
            'prior_allocations' => [['manual_student_debt_id' => $debt->id, 'amount' => 8000]],
        ]))->assertStatus(422);

        $debt->refresh();
        $this->assertSame(7000.0, $debt->outstanding());
    }

    public function test_cancelling_payment_returns_manual_debt_to_full_outstanding(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$student, , $currentYearId, $enrollment] = $this->studentWithTwoYears();
        $debt = $this->enterManualDebt($student->id, $currentYearId, 10000);

        $receipt = $this->postJson('/api/payments/collect', $this->collectPayload($student->id, $enrollment->id, [
            'prior_allocations' => [['manual_student_debt_id' => $debt->id, 'amount' => 3000]],
        ]))->json('receipt');

        $this->postJson('/api/payments/'.$receipt['payment_id'].'/cancel', ['reason' => 'خطأ في المبلغ'])
            ->assertOk();

        // الرسم الجسر يقف بذاته (سجلّ الدَّين) فلا يُمحى بإلغاء الوصل.
        $debt->refresh();
        $this->assertDatabaseHas('student_fees', ['id' => $debt->source_student_fee_id]);
        $this->assertSame(10000.0, $debt->outstanding());
        $this->assertSame(ManualStudentDebt::STATUS_PENDING, $debt->status);

        // الدَّين قابل للتحصيل من جديد بعد الإلغاء.
        $this->postJson('/api/payments/collect', $this->collectPayload($student->id, $enrollment->id, [
            'prior_allocations' => [['manual_student_debt_id' => $debt->id, 'amount' => 3000]],
        ]))->assertCreated();
    }

    public function test_opening_balances_summary_includes_manual_totals(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$student, , $currentYearId, $enrollment] = $this->studentWithTwoYears();
        $debt = $this->enterManualDebt($student->id, $currentYearId, 10000);

        $this->postJson('/api/payments/collect', $this->collectPayload($student->id, $enrollment->id, [
            'prior_allocations' => [['manual_student_debt_id' => $debt->id, 'amount' => 3000]],
        ]))->assertCreated();

        $this->getJson('/api/reports/opening-balances-summary?academic_year_id='.$currentYearId)
            ->assertOk()
            ->assertJsonPath('summary.manual.count', 1)
            ->assertJsonPath('summary.manual.original_amount', 10000, false)
            ->assertJsonPath('summary.manual.outstanding_amount', 7000, false)
            ->assertJsonPath('summary.grand_total.outstanding_amount', 7000, false)
            ->assertJsonStructure([
                'summary' => ['auto', 'manual' => ['by_type'], 'grand_total'],
            ]);
    }

    public function test_regular_allocation_leaves_debt_target_ids_null(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$student, , $currentYearId, $enrollment] = $this->studentWithTwoYears();
        $feeType = $this->makeFeeType('القسط الشهري', 4000);

        $this->postJson('/api/payments/collect', $this->collectPayload($student->id, $enrollment->id, [
            'months' => ['2025-09'],
            'items' => [['fee_type_id' => $feeType->id, 'amount' => 4000]],
        ]))->assertCreated();

        $allocation = PaymentAllocation::first();
        $this->assertNotNull($allocation);
        $this->assertNull($allocation->manual_student_debt_id);
        $this->assertNull($allocation->opening_balance_id);
    }

    public function test_manual_student_debt_collection_saves_explicit_manual_student_debt_id(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$student, , $currentYearId, $enrollment] = $this->studentWithTwoYears();
        $debt = $this->enterManualDebt($student->id, $currentYearId, 10000);

        $this->postJson('/api/payments/collect', $this->collectPayload($student->id, $enrollment->id, [
            'prior_allocations' => [['manual_student_debt_id' => $debt->id, 'amount' => 3000]],
        ]))->assertCreated();

        $allocation = PaymentAllocation::first();
        $this->assertNotNull($allocation);
        $this->assertSame($debt->id, $allocation->manual_student_debt_id);
        $this->assertNull($allocation->opening_balance_id);
        $this->assertSame($debt->source_student_fee_id, $allocation->student_fee_id);

        $this->assertSame(3000.0, $debt->collected());
        $this->assertSame(7000.0, $debt->outstanding());
    }

    public function test_two_debts_sharing_source_fee_do_not_mix_payments(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$student, , $currentYearId, $enrollment] = $this->studentWithTwoYears();
        $debt1 = $this->enterManualDebt($student->id, $currentYearId, 5000);

        // إنشاء دين ثانٍ بنفس الرسم الجسر (لاختبار العزل المحاسبي الصريح)
        $debt2 = ManualStudentDebt::create([
            'student_id' => $student->id,
            'academic_year_id' => $currentYearId,
            'source_student_fee_id' => $debt1->source_student_fee_id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'tuition',
            'description' => 'دين ثانٍ',
            'original_amount' => 3000,
            'status' => ManualStudentDebt::STATUS_PENDING,
        ]);

        $this->postJson('/api/payments/collect', $this->collectPayload($student->id, $enrollment->id, [
            'prior_allocations' => [['manual_student_debt_id' => $debt1->id, 'amount' => 2000]],
        ]))->assertCreated();

        $debt1->refresh();
        $debt2->refresh();

        // التخصيص محسوب للدين 1 فقط بفضل manual_student_debt_id الصريح
        $this->assertSame(2000.0, $debt1->collected());
        $this->assertSame(3000.0, $debt1->outstanding());

        $this->assertSame(0.0, $debt2->collected());
        $this->assertSame(3000.0, $debt2->outstanding());
    }

    public function test_cannot_collect_more_than_manual_debt_outstanding_even_if_fee_is_larger(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$student, , $currentYearId, $enrollment] = $this->studentWithTwoYears();
        // الرسم الجسر بـ 10000 د.ت
        $debt1 = $this->enterManualDebt($student->id, $currentYearId, 10000);

        // دين ثانٍ محدد بـ 2000 د.ت فقط على نفس الرسم الجسر
        $debt2 = ManualStudentDebt::create([
            'student_id' => $student->id,
            'academic_year_id' => $currentYearId,
            'source_student_fee_id' => $debt1->source_student_fee_id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'tuition',
            'description' => 'دين محدد بـ 2000',
            'original_amount' => 2000,
            'status' => ManualStudentDebt::STATUS_PENDING,
        ]);

        // محاولة دفع 3000 د.ت للدين الثاني (أكبر من رصيده 2000 رغم أن الرسم 10000)
        $this->postJson('/api/payments/collect', $this->collectPayload($student->id, $enrollment->id, [
            'prior_allocations' => [['manual_student_debt_id' => $debt2->id, 'amount' => 3000]],
        ]))->assertStatus(422);

        $debt2->refresh();
        $this->assertSame(0.0, $debt2->collected());
        $this->assertSame(2000.0, $debt2->outstanding());

        // دفع مبلغ مسموح ضمن رصيد الدين الثاني (1500 د.ت)
        $this->postJson('/api/payments/collect', $this->collectPayload($student->id, $enrollment->id, [
            'prior_allocations' => [['manual_student_debt_id' => $debt2->id, 'amount' => 1500]],
        ]))->assertCreated();

        $debt2->refresh();
        $this->assertSame(1500.0, $debt2->collected());
        $this->assertSame(500.0, $debt2->outstanding());
    }

    public function test_zero_and_negative_prior_allocation_is_rejected_and_no_payment_is_created(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$student, , $currentYearId, $enrollment] = $this->studentWithTwoYears();
        $debt = $this->enterManualDebt($student->id, $currentYearId, 10000);

        // محاولة دفع مبلغ سالب
        $this->postJson('/api/payments/collect', $this->collectPayload($student->id, $enrollment->id, [
            'prior_allocations' => [['manual_student_debt_id' => $debt->id, 'amount' => -500]],
        ]))->assertStatus(422);

        // محاولة دفع مبلغ صفر
        $this->postJson('/api/payments/collect', $this->collectPayload($student->id, $enrollment->id, [
            'prior_allocations' => [['manual_student_debt_id' => $debt->id, 'amount' => 0]],
        ]))->assertStatus(422);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('cash_transactions', 0);
        $this->assertSame(10000.0, $debt->fresh()->outstanding());
    }

    public function test_duplicate_active_debt_creation_in_store_is_rejected_for_same_student_year_and_type(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$student, , $currentYearId] = $this->studentWithTwoYears();

        $this->enterManualDebt($student->id, $currentYearId, 5000);

        // محاولة إدخال دين ثانٍ نشط بنفس النوع والتلميذ والسنة
        $response = $this->postJson('/api/manual-debts', [
            'student_id' => $student->id,
            'academic_year_id' => $currentYearId,
            'original_year_label' => '2024/2025',
            'debt_type' => 'tuition',
            'description' => 'دين مكرر',
            'original_amount' => 3000,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('manual_student_debts', 1);
    }

    public function test_different_debt_types_for_same_student_and_year_can_be_stored(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$student, , $currentYearId] = $this->studentWithTwoYears();

        $this->enterManualDebt($student->id, $currentYearId, 5000); // tuition

        // إدخال دين بنوع مختلف (registration) لنفس التلميذ والسنة
        $response = $this->postJson('/api/manual-debts', [
            'student_id' => $student->id,
            'academic_year_id' => $currentYearId,
            'original_year_label' => '2024/2025',
            'debt_type' => 'registration',
            'description' => 'معلوم تسجيل متخلد',
            'original_amount' => 1200,
        ]);

        $response->assertCreated();
        $this->assertDatabaseCount('manual_student_debts', 2);
    }

    public function test_multiple_installments_collecting_debt_to_full_paid(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$student, , $currentYearId, $enrollment] = $this->studentWithTwoYears();
        $debt = $this->enterManualDebt($student->id, $currentYearId, 10000);

        // القسط 1: 4000 د.ت
        $this->postJson('/api/payments/collect', $this->collectPayload($student->id, $enrollment->id, [
            'prior_allocations' => [['manual_student_debt_id' => $debt->id, 'amount' => 4000]],
        ]))->assertCreated();

        $debt->refresh();
        $this->assertSame(4000.0, $debt->collected());
        $this->assertSame(6000.0, $debt->outstanding());
        $this->assertSame(ManualStudentDebt::STATUS_PARTIAL, $debt->status);

        // القسط 2: 6000 د.ت (سداد كامل)
        $this->postJson('/api/payments/collect', $this->collectPayload($student->id, $enrollment->id, [
            'prior_allocations' => [['manual_student_debt_id' => $debt->id, 'amount' => 6000]],
        ]))->assertCreated();

        $debt->refresh();
        $this->assertSame(10000.0, $debt->collected());
        $this->assertSame(0.0, $debt->outstanding());
        $this->assertSame(ManualStudentDebt::STATUS_PAID, $debt->status);

        // محاولة دفع إضافي بعد السداد الكامل
        $this->postJson('/api/payments/collect', $this->collectPayload($student->id, $enrollment->id, [
            'prior_allocations' => [['manual_student_debt_id' => $debt->id, 'amount' => 1000]],
        ]))->assertStatus(422);
    }

    public function test_manual_debt_collect_endpoint_and_payments_history_and_statement(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$student, , $currentYearId] = $this->studentWithTwoYears();
        $debt = $this->enterManualDebt($student->id, $currentYearId, 5000);

        // تحصيل عبر POST /manual-debts/{debt}/collect
        $this->postJson('/api/manual-debts/'.$debt->id.'/collect', [
            'amount' => 2000,
            'payment_date' => '2025-09-10',
            'method' => 'cash',
        ])->assertCreated()
            ->assertJsonPath('debt.collected_amount', 2000)
            ->assertJsonPath('debt.outstanding_amount', 3000);

        // فحص سجل الدفعات GET /manual-debts/{debt}/payments
        $this->getJson('/api/manual-debts/'.$debt->id.'/payments')
            ->assertOk()
            ->assertJsonPath('totals.paid_active', 2000)
            ->assertJsonPath('totals.count', 1);

        // فحص كشف الطباعة GET /manual-debts/{debt}/statement
        $this->getJson('/api/manual-debts/'.$debt->id.'/statement')
            ->assertOk()
            ->assertJsonPath('debt.original_amount', 5000)
            ->assertJsonPath('debt.paid_amount', 2000)
            ->assertJsonPath('debt.outstanding_amount', 3000);

        // فحص ملخص ديون التلميذ GET /students/{student}/old-debt-summary
        $this->getJson('/api/students/'.$student->id.'/old-debt-summary')
            ->assertOk()
            ->assertJsonPath('totals.count', 1)
            ->assertJsonPath('totals.outstanding_amount', 3000);
    }

    public function test_manual_debt_collection_requires_manage_payments_permission(): void
    {
        $userWithoutPermission = $this->makeUser('viewer');
        Sanctum::actingAs($userWithoutPermission);

        [$student, , $currentYearId, $enrollment] = $this->studentWithTwoYears();
        $debt = ManualStudentDebt::create([
            'student_id' => $student->id,
            'academic_year_id' => $currentYearId,
            'original_year_label' => '2024/2025',
            'debt_type' => 'tuition',
            'description' => 'دين',
            'original_amount' => 5000,
            'status' => ManualStudentDebt::STATUS_PENDING,
        ]);

        $this->postJson('/api/payments/collect', $this->collectPayload($student->id, $enrollment->id, [
            'prior_allocations' => [['manual_student_debt_id' => $debt->id, 'amount' => 1000]],
        ]))->assertForbidden();

        $this->postJson('/api/manual-debts/'.$debt->id.'/collect', [
            'amount' => 1000,
        ])->assertForbidden();
    }
}
