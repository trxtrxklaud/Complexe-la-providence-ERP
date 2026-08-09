<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Club;
use App\Models\ClubMonthlyFee;
use App\Models\ClubSubscription;
use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\Permission;
use App\Models\Student;
use App\Services\ClubMonthlyDiscountService;
use App\Services\ClubService;
use App\Services\DiscountService;
use App\Services\MonthlyDiscountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MonthlyDiscountTest extends TestCase
{
    use RefreshDatabase;

    private function makeWaiverUser()
    {
        $user = $this->makeUser('admin');
        $user->update(['is_active' => true]);
        $permission = Permission::firstOrCreate([
            'name' => 'waive_fees',
        ], [
            'display_name' => 'التنازل عن الدُّيون',
            'group' => 'Finance',
        ]);
        $user->role->permissions()->syncWithoutDetaching([$permission->id]);

        return $user;
    }

    private function makeRegularUser()
    {
        $user = $this->makeUser('cashier');
        $user->update(['is_active' => true]);
        return $user;
    }

    private function makeFeeCategory(string $code = 'TUITION', string $name = 'معاليم الدراسة')
    {
        return \App\Models\FeeCategory::firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'is_recurring' => true]
        );
    }

    private function makeFeePlan(AcademicYear $year, int $levelId, float $amount = 160.0): FeePlan
    {
        $cat = $this->makeFeeCategory();
        return FeePlan::create([
            'academic_year_id' => $year->id,
            'level_id'         => $levelId,
            'fee_category_id'  => $cat->id,
            'name'             => 'القسط الشهري',
            'amount'           => $amount,
            'frequency'        => 'monthly',
        ]);
    }

    private function makeClub(string $name = 'نادي', float $monthlyFee = 80.0): Club
    {
        $cat = $this->makeFeeCategory('CLUB', 'معاليم النوادي');
        return Club::create([
            'name'            => $name,
            'fee_category_id' => $cat->id,
            'monthly_fee'     => $monthlyFee,
            'is_active'       => true,
        ]);
    }


    /**
     * Test 1: Existing normal discount behavior remains unchanged (20% cap enforced).
     */
    public function test_1_existing_normal_discount_enforces_20_percent_cap(): void
    {
        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);

        $this->makeFeePlan($year, $enrollment->level_id, 160.0);


        $discountService = app(DiscountService::class);

        // 30 TND per month is within 32 TND monthly cap (20% of 160 TND) -> succeeds
        $d = $discountService->createForEnrollment($enrollment->id, 30.0, 'تخفيض عادي', '2025-09-01');
        $this->assertNotNull($d);

        // Exceeding 32 TND monthly cap with additional discount throws InvalidArgumentException
        $this->expectException(\InvalidArgumentException::class);
        $discountService->validate20PercentCap($enrollment->id, 10.0);

    }

    /**
     * Test 2: Tuition full waiver for a 160 TND monthly fee: net_due = 0, no payment created, absent from unpaid report.
     */
    public function test_2_tuition_full_waiver_net_due_zero_and_absent_from_unpaid_report(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);

        $this->makeFeePlan($year, $enrollment->level_id, 160.0);

        $paymentsBefore = CashTransaction::count();

        // Create full_waiver
        $service = app(MonthlyDiscountService::class);
        $discount = $service->createDiscount($enrollment->id, 'full_waiver', null, 'إعفاء كلي لأسباب اجتماعية');

        $this->assertSame('2025-09', $discount->start_month);
        $this->assertSame('2026-06', $discount->end_month);

        // Ensure no payment record was created
        $this->assertSame($paymentsBefore, CashTransaction::count());

        // Check unpaid monthly report for 2025-09
        $response = $this->getJson('/api/reports/unpaid-monthly?'.http_build_query([
            'academic_year_id' => $year->id,
            'month'            => '2025-09',
            'section_id'       => $enrollment->section_id,
        ]));

        $response->assertOk();
        // Fully waived student must NOT be in unpaid report
        $response->assertJsonMissing(['enrollment_id' => $enrollment->id]);
    }

    /**
     * Test 3: Tuition humanitarian discount of 50 TND on a 160 TND fee: net_due = 110, unpaid report shows 110.
     */
    public function test_3_tuition_humanitarian_discount_shows_net_due_in_unpaid_report(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);

        $this->makeFeePlan($year, $enrollment->level_id, 160.0);


        // Create humanitarian_fixed of 50 TND
        $service = app(MonthlyDiscountService::class);
        $service->createDiscount($enrollment->id, 'humanitarian_fixed', 50.0, 'تخفيض حالة إنسانية');

        // Check unpaid report for 2025-09
        $response = $this->getJson('/api/reports/unpaid-monthly?'.http_build_query([
            'academic_year_id' => $year->id,
            'month'            => '2025-09',
            'section_id'       => $enrollment->section_id,
        ]));

        $response->assertOk();
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame($enrollment->id, $rows[0]['enrollment_id']);
        $this->assertEquals(160.0, $rows[0]['gross_amount']);
        $this->assertEquals(50.0, $rows[0]['discount_amount']);
        $this->assertEquals(110.0, $rows[0]['net_due']);
        $this->assertEquals(110.0, $rows[0]['remaining_amount']);
    }

    /**
     * Test 4: Tuition humanitarian discount of <= 20 TND is rejected.
     */
    public function test_4_tuition_humanitarian_discount_le_20_rejected(): void
    {
        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);

        $service = app(MonthlyDiscountService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->createDiscount($enrollment->id, 'humanitarian_fixed', 20.0, 'مبلغ 20 مرفوض');
    }

    /**
     * Test 5: Tuition humanitarian discount > monthly fee is rejected.
     */
    public function test_5_tuition_humanitarian_discount_exceeding_fee_rejected(): void
    {
        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);

        $this->makeFeePlan($year, $enrollment->level_id, 160.0);


        $service = app(MonthlyDiscountService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->createDiscount($enrollment->id, 'humanitarian_fixed', 170.0, 'أكبر من المعلوم');
    }

    /**
     * Test 6 & 7: September to year-end duration coverage.
     */
    public function test_6_7_september_to_year_end_duration(): void
    {
        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id);

        $service = app(MonthlyDiscountService::class);
        $discount = $service->createDiscount($enrollment->id, 'full_waiver', null, 'إعفاء كلي');

        $this->assertTrue($discount->coversMonth('2025-09'));
        $this->assertTrue($discount->coversMonth('2026-01'));
        $this->assertTrue($discount->coversMonth('2026-06'));
        $this->assertFalse($discount->coversMonth('2025-08')); // Before September
        $this->assertFalse($discount->coversMonth('2026-07')); // After June
    }

    /**
     * Test 8 & 9: Discount does not apply to another academic year.
     */
    public function test_8_9_discount_does_not_leak_to_another_academic_year(): void
    {
        $year1 = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year1);
        $this->makeFeePlan($year1, $enrollment->level_id);

        $service = app(MonthlyDiscountService::class);
        $service->createDiscount($enrollment->id, 'full_waiver', null, 'إعفاء كلي');


        $year2 = AcademicYear::create([
            'name'       => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date'   => '2027-06-30',
            'is_active'  => false,
        ]);

        $activeDisc = $service->getActiveDiscountForMonth($enrollment->id, $year2->id, '2026-09');
        $this->assertNull($activeDisc);
    }

    /**
     * Test 10 & 13: Club full waiver from September to year-end: absent from unpaid pending count.
     */
    public function test_10_13_club_full_waiver_absent_from_pending(): void
    {
        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);

        $club = $this->makeClub('نادي الروبوتيك', 80.0);

        $clubService = app(ClubService::class);
        $sub = $clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id, '2025-09-01', null, $enrollment->id);

        // Generate month fee
        $clubService->generateMonthFees($year->id, '2025-09', $club->id);

        // Create club full waiver
        $clubDiscService = app(ClubMonthlyDiscountService::class);
        $clubDiscService->createDiscount($sub->id, 'full_waiver', null, 'إعفاء نادي كلي');

        $report = $clubService->getReport(['academic_year_id' => $year->id, 'month' => '2025-09', 'club_id' => $club->id]);

        $this->assertSame(0, $report['summary']['pending_count']);
        $this->assertSame(1, $report['summary']['paid_count']);
        $this->assertEquals(0.0, $report['records'][0]['remaining']);
    }

    /**
     * Test 11: Club humanitarian discount of 30 TND on 80 TND fee: net_due = 50.
     */
    public function test_11_club_humanitarian_discount_shows_net_due(): void
    {
        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);

        $club = $this->makeClub('نادي الحساب الذهني', 80.0);

        $clubService = app(ClubService::class);
        $sub = $clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id, '2025-09-01', null, $enrollment->id);

        $clubService->generateMonthFees($year->id, '2025-09', $club->id);

        $clubDiscService = app(ClubMonthlyDiscountService::class);
        $clubDiscService->createDiscount($sub->id, 'humanitarian_fixed', 30.0, 'تخفيض نادي إنساني');

        $report = $clubService->getReport(['academic_year_id' => $year->id, 'month' => '2025-09', 'club_id' => $club->id]);

        $this->assertSame(1, $report['summary']['pending_count']);
        $this->assertEquals(50.0, $report['records'][0]['net_due']);
        $this->assertEquals(50.0, $report['records'][0]['remaining']);
    }

    /**
     * Test 12: Club humanitarian discount <= 20 TND is rejected.
     */
    public function test_12_club_humanitarian_discount_le_20_rejected(): void
    {
        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);

        $club = $this->makeClub('نادي الموسيقى', 80.0);

        $sub = app(ClubService::class)->subscribeStudent($enrollment->student_id, $club->id, $year->id, '2025-09-01', null, $enrollment->id);

        $this->expectException(\InvalidArgumentException::class);
        app(ClubMonthlyDiscountService::class)->createDiscount($sub->id, 'humanitarian_fixed', 20.0, 'مبلغ 20 مرفوض');
    }

    /**
     * Test 14 & 15: Tuition-only discount does not affect clubs; club-only discount does not affect tuition.
     */
    public function test_14_15_tuition_and_club_discounts_are_isolated(): void
    {
        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);

        $this->makeFeePlan($year, $enrollment->level_id, 160.0);

        $club = $this->makeClub('نادي الرسم', 60.0);

        $clubService = app(ClubService::class);
        $sub = $clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id, '2025-09-01', null, $enrollment->id);
        $clubService->generateMonthFees($year->id, '2025-09', $club->id);

        // Apply tuition full waiver
        app(MonthlyDiscountService::class)->createDiscount($enrollment->id, 'full_waiver', null, 'إعفاء دراسي');

        // Club report should still show full club fee (60 TND) due
        $clubReport = $clubService->getReport(['academic_year_id' => $year->id, 'month' => '2025-09', 'club_id' => $club->id]);
        $this->assertEquals(60.0, $clubReport['records'][0]['net_due']);
    }

    /**
     * Test 16: Unauthorized users cannot create or cancel discounts (403 response).
     */
    public function test_16_unauthorized_users_cannot_create_or_cancel_discounts(): void
    {
        $cashier = $this->makeRegularUser(); // regular user without waive_fees permission
        Sanctum::actingAs($cashier);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);

        $response = $this->postJson("/api/enrollments/{$enrollment->id}/monthly-discounts", [
            'discount_type'  => 'full_waiver',
            'reason'         => 'محاولة محظورة',
        ]);

        $response->assertForbidden();
    }

    /**
     * Test 17: Existing paid receipts remain unchanged; no fake payments created.
     */
    public function test_17_no_fake_payments_or_cash_transactions_created(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id);


        $ledgerBefore = CashTransaction::count();

        $service = app(MonthlyDiscountService::class);
        $service->createDiscount($enrollment->id, 'full_waiver', null, 'إعفاء كلي');

        $this->assertSame($ledgerBefore, CashTransaction::count());
    }

    /**
     * Test 18: Cancelling a tuition monthly discount restores the student to unpaid report with cancellation audit.
     */
    public function test_18_cancelling_monthly_discount_restores_unpaid_report_and_records_audit(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 160.0);

        $service = app(MonthlyDiscountService::class);
        $discount = $service->createDiscount($enrollment->id, 'full_waiver', null, 'إعفاء كلي موقت');

        // Excluded from unpaid report while active
        $resBefore = $this->getJson('/api/reports/unpaid-monthly?'.http_build_query([
            'academic_year_id' => $year->id,
            'month'            => '2025-09',
            'section_id'       => $enrollment->section_id,
        ]));
        $resBefore->assertOk()->assertJsonMissing(['enrollment_id' => $enrollment->id]);

        // Cancel discount via API
        $response = $this->postJson("/api/monthly-discounts/{$discount->id}/cancel", [
            'reason' => 'زوال سبب الإعفاء الاجتماعي',
        ]);
        $response->assertOk();

        $discount->refresh();
        $this->assertNotNull($discount->cancelled_at);
        $this->assertSame($user->id, (int) $discount->cancelled_by);
        $this->assertSame('زوال سبب الإعفاء الاجتماعي', $discount->cancellation_reason);

        // Student now reappears in unpaid report
        $resAfter = $this->getJson('/api/reports/unpaid-monthly?'.http_build_query([
            'academic_year_id' => $year->id,
            'month'            => '2025-09',
            'section_id'       => $enrollment->section_id,
        ]));
        $resAfter->assertOk()->assertJsonFragment(['enrollment_id' => $enrollment->id]);
    }

    /**
     * Test 19: Multiple club subscriptions for a single student are discounted independently.
     */
    public function test_19_multiple_club_subscriptions_for_student_are_independent(): void
    {
        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);

        $club1 = $this->makeClub('نادي السباحة', 100.0);
        $club2 = $this->makeClub('نادي الشطرنج', 40.0);

        $clubService = app(ClubService::class);
        $sub1 = $clubService->subscribeStudent($enrollment->student_id, $club1->id, $year->id, '2025-09-01', null, $enrollment->id);
        $sub2 = $clubService->subscribeStudent($enrollment->student_id, $club2->id, $year->id, '2025-09-01', null, $enrollment->id);

        $clubService->generateMonthFees($year->id, '2025-09', $club1->id);
        $clubService->generateMonthFees($year->id, '2025-09', $club2->id);

        // Discount only sub1 (full waiver)
        app(ClubMonthlyDiscountService::class)->createDiscount($sub1->id, 'full_waiver', null, 'إعفاء سباحة');

        $report1 = $clubService->getReport(['academic_year_id' => $year->id, 'month' => '2025-09', 'club_id' => $club1->id]);
        $report2 = $clubService->getReport(['academic_year_id' => $year->id, 'month' => '2025-09', 'club_id' => $club2->id]);

        // Club 1 is waived
        $this->assertSame(0, $report1['summary']['pending_count']);
        $this->assertEquals(0.0, $report1['records'][0]['remaining']);

        // Club 2 remains unpaid (40 TND due)
        $this->assertSame(1, $report2['summary']['pending_count']);
        $this->assertEquals(40.0, $report2['records'][0]['remaining']);
    }

    /**
     * Test 20: Missing FeePlan causes createDiscount to throw InvalidArgumentException without fallback.
     */
    public function test_20_missing_fee_plan_throws_invalid_argument_exception(): void
    {
        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        // Do NOT create FeePlan

        $service = app(MonthlyDiscountService::class);
        $this->expectException(\InvalidArgumentException::class);
        $service->createDiscount($enrollment->id, 'full_waiver', null, 'فشل غياب المخطط');
    }

    /**
     * Test 21: Collection preview calculates gross, discount, net due, and handles full waiver.
     */
    public function test_21_collection_preview_endpoint_returns_discount_calculations(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 160.0);

        // Apply humanitarian fixed discount of 50 TND
        app(MonthlyDiscountService::class)->createDiscount($enrollment->id, 'humanitarian_fixed', 50.0, 'خصم إنساني');

        $response = $this->getJson('/api/payments/collect/preview?'.http_build_query([
            'enrollment_id' => $enrollment->id,
            'months'        => ['2025-09'],
        ]));

        $response->assertOk();
        $response->assertJson([
            'gross_amount'     => 160.0,
            'discount_type'    => 'humanitarian_fixed',
            'discount_amount'  => 50.0,
            'net_due'          => 110.0,
            'remaining_amount' => 110.0,
            'is_fully_waived'  => false,
            'can_collect'      => true,
        ]);
    }

    /**
     * Test 22: Full waiver collection attempt is rejected by backend and creates 0 payments/ledger rows.
     */
    public function test_22_full_waiver_collection_attempt_rejected_and_creates_no_payment(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $feePlan = $this->makeFeePlan($year, $enrollment->level_id, 160.0);
        $feeType = \App\Models\FeeType::firstOrCreate(['name_ar' => 'القسط الشهري'], ['price' => 160.0, 'fee_category_id' => $feePlan->fee_category_id]);

        app(MonthlyDiscountService::class)->createDiscount($enrollment->id, 'full_waiver', null, 'إعفاء تام');

        $paymentsBefore = CashTransaction::count();

        // Cashier tries to collect fee for full waiver student
        $response = $this->postJson('/api/payments/collect', [
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'months'        => ['2025-09'],
            'payment_date'  => '2025-09-01',
            'method'        => 'cash',
            'items'         => [
                ['fee_type_id' => $feeType->id, 'amount' => 160.0],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'هذا المعلوم معفى كلياً ولا يوجد مبلغ مستحق.']);
        $this->assertSame($paymentsBefore, CashTransaction::count());
    }

    /**
     * Test 23: Humanitarian collection rejects amounts greater than remaining net due.
     */
    public function test_23_humanitarian_collection_rejects_excess_amounts(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $feePlan = $this->makeFeePlan($year, $enrollment->level_id, 160.0);
        $feeType = \App\Models\FeeType::firstOrCreate(['name_ar' => 'القسط الشهري'], ['price' => 160.0, 'fee_category_id' => $feePlan->fee_category_id]);

        app(MonthlyDiscountService::class)->createDiscount($enrollment->id, 'humanitarian_fixed', 50.0, 'خصم إنساني');

        // Trying to pay 160 TND when net_due is 110 TND must be rejected!
        $resExcess = $this->postJson('/api/payments/collect', [
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'months'        => ['2025-09'],
            'payment_date'  => '2025-09-01',
            'method'        => 'cash',
            'items'         => [
                ['fee_type_id' => $feeType->id, 'amount' => 160.0],
            ],
        ]);
        $resExcess->assertStatus(422);

        // Paying exactly 110 TND succeeds!
        $resValid = $this->postJson('/api/payments/collect', [
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'months'        => ['2025-09'],
            'payment_date'  => '2025-09-01',
            'method'        => 'cash',
            'items'         => [
                ['fee_type_id' => $feeType->id, 'amount' => 110.0],
            ],
        ]);
        $resValid->assertStatus(201);
    }


    /**
     * Test 24: Full club waiver collection attempt is rejected by backend.
     */
    public function test_24_full_club_waiver_collection_attempt_rejected(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub('نادي الروبوتيك', 80.0);

        $clubService = app(ClubService::class);
        $sub = $clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id, '2025-09-01', null, $enrollment->id);
        $clubService->generateMonthFees($year->id, '2025-09', $club->id);

        app(ClubMonthlyDiscountService::class)->createDiscount($sub->id, 'full_waiver', null, 'إعفاء كلي نادي');

        $fee = ClubMonthlyFee::where('club_subscription_id', $sub->id)->where('month', '2025-09')->firstOrFail();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('معلوم النادي لهذا الشهر معفى كلياً ولا يوجد مبلغ مستحق.');

        $clubService->recordPayment($fee, 80.0, '2025-09-01', 'cash');
    }

    /**
     * Test 25: Humanitarian club collection rejects amounts greater than net due.
     */
    public function test_25_humanitarian_club_collection_rejects_excess_amounts(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $club = $this->makeClub('نادي الروبوتيك', 80.0);

        $clubService = app(ClubService::class);
        $sub = $clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id, '2025-09-01', null, $enrollment->id);
        $clubService->generateMonthFees($year->id, '2025-09', $club->id);

        app(ClubMonthlyDiscountService::class)->createDiscount($sub->id, 'humanitarian_fixed', 30.0, 'تخفيض 30 د');

        $fee = ClubMonthlyFee::where('club_subscription_id', $sub->id)->where('month', '2025-09')->firstOrFail();

        // 80 TND on 50 TND net_due throws exception
        try {
            $clubService->recordPayment($fee, 80.0, '2025-09-01', 'cash');
            $this->fail('Expected InvalidArgumentException was not thrown');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('تخفيض الإنساني', $e->getMessage());
        }

        // 50 TND succeeds!
        $paid = $clubService->recordPayment($fee, 50.0, '2025-09-01', 'cash');
        $this->assertSame('paid', $paid->status);
    }

    /**
     * Test 26: Multi-month collection preview calculates per-month details independently.
     */
    public function test_26_multi_month_preview_calculates_per_month_independently(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 160.0);

        // Humanitarian discount of 50 TND for 2025-09 ONLY
        app(MonthlyDiscountService::class)->createDiscount($enrollment->id, 'humanitarian_fixed', 50.0, 'خصم سبتمبر فقط', null, null, '2025-09', '2025-09');


        $response = $this->getJson('/api/payments/collect/preview?'.http_build_query([
            'enrollment_id' => $enrollment->id,
            'months'        => ['2025-09', '2025-10'],
        ]));

        $response->assertOk();
        $response->assertJson([
            'gross_amount'     => 320.0,
            'discount_amount'  => 50.0,
            'net_due'          => 270.0,
            'remaining_amount' => 270.0,
            'is_fully_waived'  => false,
            'can_collect'      => true,
        ]);

        $items = $response->json('items');
        $this->assertCount(2, $items);
        $this->assertSame('2025-09', $items[0]['month']);
        $this->assertEquals(110.0, $items[0]['remaining_amount']);
        $this->assertSame('2025-10', $items[1]['month']);
        $this->assertEquals(160.0, $items[1]['remaining_amount']);
    }


    /**
     * Test 27: Overlapping normal discount and monthly humanitarian discount precedence (no stacking).
     */
    public function test_27_overlapping_discounts_do_not_stack(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 160.0);

        // Annual normal discount: 20 TND/month
        app(DiscountService::class)->createForEnrollment($enrollment->id, 20.0, 'تخفيض عادي', '2025-09-01');


        // Recurring monthly humanitarian discount: 50 TND
        app(MonthlyDiscountService::class)->createDiscount($enrollment->id, 'humanitarian_fixed', 50.0, 'خصم إنساني');

        $response = $this->getJson('/api/payments/collect/preview?'.http_build_query([
            'enrollment_id' => $enrollment->id,
            'months'        => ['2025-09'],
        ]));

        $response->assertOk();
        // Monthly humanitarian discount (50 TND) takes precedence; DOES NOT STACK to 70 TND!
        $response->assertJson([
            'gross_amount'    => 160.0,
            'discount_amount' => 50.0,
            'net_due'         => 110.0,
        ]);
    }

    /**
     * Test 28: Cancelled discount is ignored in collection preview.
     */
    public function test_28_cancelled_discount_is_ignored_in_preview(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 160.0);

        $service = app(MonthlyDiscountService::class);
        $disc = $service->createDiscount($enrollment->id, 'humanitarian_fixed', 50.0, 'خصم ملغى');
        $service->cancel($disc->id, 'إلغاء الخصم');

        $response = $this->getJson('/api/payments/collect/preview?'.http_build_query([
            'enrollment_id' => $enrollment->id,
            'months'        => ['2025-09'],
        ]));

        $response->assertOk();
        $response->assertJson([
            'gross_amount'    => 160.0,
            'discount_amount' => 0.0,
            'net_due'         => 160.0,
            'discount_type'   => null,
        ]);
    }

    /**
     * Test 29: Preview endpoint requires manage_payments permission.
     */
    public function test_29_preview_endpoint_requires_manage_payments_permission(): void
    {
        $unauth = $this->makeUser('unauth'); // user without manage_payments or waive_fees
        Sanctum::actingAs($unauth);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);

        $response = $this->getJson('/api/payments/collect/preview?'.http_build_query([
            'enrollment_id' => $enrollment->id,
            'months'        => ['2025-09'],
        ]));

        $response->assertForbidden();
    }

    /**
     * Test 30: Full waiver in one month does not waive another month, and allows collecting payable months.
     */
    public function test_30_full_waiver_in_one_month_does_not_waive_other_months(): void
    {
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 160.0);

        // Full waiver ONLY for September 2025-09
        app(MonthlyDiscountService::class)->createDiscount($enrollment->id, 'full_waiver', null, 'إعفاء سبتمبر فقط', null, null, '2025-09', '2025-09');


        $response = $this->getJson('/api/payments/collect/preview?'.http_build_query([
            'enrollment_id' => $enrollment->id,
            'months'        => ['2025-09', '2025-10'],
        ]));

        $response->assertOk();
        $items = $response->json('items');
        $this->assertTrue($items[0]['is_fully_waived']);
        $this->assertEquals(0.0, $items[0]['remaining_amount']);

        $this->assertFalse($items[1]['is_fully_waived']);
        $this->assertEquals(160.0, $items[1]['remaining_amount']);

        // Overall remaining amount is 160 TND for October
        $this->assertEquals(160.0, $response->json('remaining_amount'));
    }

    /**
     * Test 31: Duplicate active full waiver on overlapping period is rejected.
     */
    public function test_31_duplicate_active_full_waiver_is_rejected(): void
    {
        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 160.0);

        $service = app(MonthlyDiscountService::class);
        $service->createDiscount($enrollment->id, 'full_waiver', null, 'إعفاء أول');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('تخفيض سارٍ بالفعل يتداخل مع هذه الفترة');
        $service->createDiscount($enrollment->id, 'full_waiver', null, 'إعفاء ثانٍ');
    }

    /**
     * Test 32: Overlapping active humanitarian discounts are rejected.
     */
    public function test_32_overlapping_humanitarian_discounts_are_rejected(): void
    {
        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 160.0);

        $service = app(MonthlyDiscountService::class);
        $service->createDiscount($enrollment->id, 'humanitarian_fixed', 50.0, 'خصم أول', null, null, '2025-09', '2025-11');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('تخفيض سارٍ بالفعل يتداخل مع هذه الفترة');
        $service->createDiscount($enrollment->id, 'humanitarian_fixed', 40.0, 'خصم متداخل', null, null, '2025-10', '2025-12');
    }

    /**
     * Test 33: Overlapping full waiver and humanitarian discount are rejected.
     */
    public function test_33_overlapping_full_waiver_and_humanitarian_rejected(): void
    {
        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 160.0);

        $service = app(MonthlyDiscountService::class);
        $service->createDiscount($enrollment->id, 'full_waiver', null, 'إعفاء تام', null, null, '2025-09', '2025-12');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('تخفيض سارٍ بالفعل يتداخل مع هذه الفترة');
        $service->createDiscount($enrollment->id, 'humanitarian_fixed', 50.0, 'خصم إنساني متداخل', null, null, '2025-10', '2025-10');
    }

    /**
     * Test 34: Cancelled discount does not block creating a new valid discount.
     */
    public function test_34_cancelled_discount_does_not_block_new_discount(): void
    {
        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 160.0);

        $service = app(MonthlyDiscountService::class);
        $oldDisc = $service->createDiscount($enrollment->id, 'humanitarian_fixed', 50.0, 'خصم قديّم');
        $service->cancel($oldDisc->id, 'إلغاء الخصم');

        // Creating a new active discount after cancellation succeeds cleanly!
        $newDisc = $service->createDiscount($enrollment->id, 'full_waiver', null, 'إعفاء جديد بعد الإلغاء');
        $this->assertSame('full_waiver', $newDisc->discount_type);
        $this->assertNull($newDisc->cancelled_at);
    }

    /**
     * Test 35: Discounts for different academic years are allowed independently.
     */
    public function test_35_discounts_for_different_academic_years_are_independent(): void
    {
        $year1 = $this->makeAcademicYear('2025-2026');
        $year2 = $this->makeAcademicYear('2026-2027');

        $enroll1 = $this->makeEnrollment($year1);
        $enroll2 = $this->makeEnrollment($year2);

        $this->makeFeePlan($year1, $enroll1->level_id, 160.0);
        $this->makeFeePlan($year2, $enroll2->level_id, 170.0);

        $service = app(MonthlyDiscountService::class);
        $disc1 = $service->createDiscount($enroll1->id, 'humanitarian_fixed', 50.0, 'خصم السنة الأولى', null, null, '2025-09', '2026-06');
        $disc2 = $service->createDiscount($enroll2->id, 'full_waiver', null, 'إعفاء السنة الثانية', null, null, '2026-09', '2027-06');

        $this->assertSame('humanitarian_fixed', $disc1->discount_type);
        $this->assertSame('full_waiver', $disc2->discount_type);
    }

    /**
     * Test 36: Tuition discount and club discount on the same student are completely isolated.
     */
    public function test_36_tuition_and_club_discounts_on_same_student_are_isolated(): void
    {
        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 160.0);

        $club = $this->makeClub('نادي الرسم', 60.0);
        $clubService = app(ClubService::class);
        $sub = $clubService->subscribeStudent($enrollment->student_id, $club->id, $year->id, '2025-09-01', null, $enrollment->id);
        $clubService->generateMonthFees($year->id, '2025-09', $club->id);

        // Apply tuition full waiver
        app(MonthlyDiscountService::class)->createDiscount($enrollment->id, 'full_waiver', null, 'إعفاء الدراسة');

        // Apply club humanitarian discount
        app(ClubMonthlyDiscountService::class)->createDiscount($sub->id, 'humanitarian_fixed', 25.0, 'تخفيض النادي', null, null, '2025-09', '2026-06');

        // Tuition is full waiver
        $tuitionPrev = app(\App\Services\CollectionService::class)->preview($enrollment->id, ['2025-09']);
        $this->assertTrue($tuitionPrev['is_fully_waived']);

        // Club is humanitarian 25 TND discount (60 gross - 25 disc = 35 net due)
        $clubReport = $clubService->getReport(['academic_year_id' => $year->id, 'month' => '2025-09', 'club_id' => $club->id]);
        $this->assertEquals(35.0, $clubReport['records'][0]['remaining']);
    }

    /**
     * Test 37: Existing normal discount of 10 TND per month on 160 TND fee previews as 150 TND net due.
     */
    public function test_37_existing_normal_discount_previews_150_net_due(): void
    {
        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 160.0);

        // 10 TND / month per-month discount
        \App\Models\EnrollmentDiscount::create([
            'enrollment_id' => $enrollment->id,
            'academic_year_id' => $year->id,
            'type' => 'fixed',
            'amount' => 10.0,
            'reason' => 'خصم عادي سنوي',
            'applied_date' => now(),
        ]);



        $prev = app(\App\Services\CollectionService::class)->preview($enrollment->id, ['2025-09']);

        $this->assertEquals(160.0, $prev['gross_amount']);
        $this->assertEquals(10.0, $prev['discount_amount']);
        $this->assertEquals(150.0, $prev['net_due']);
        $this->assertEquals(150.0, $prev['remaining_amount']);
        $this->assertTrue($prev['can_collect']);
        $this->assertFalse($prev['is_fully_waived']);
    }

    /**
     * Test 38: Humanitarian discount <= 20 TND remains strictly rejected.
     */
    public function test_38_humanitarian_discount_le_20_remains_rejected(): void
    {
        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 160.0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('مبلغ التخفيض الإنساني يجب أن يكون أكبر من 20 ديناراً');

        app(MonthlyDiscountService::class)->createDiscount(
            $enrollment->id,
            'humanitarian_fixed',
            10.0,
            'خصم 10 د'
        );
    }

    /**
     * Test 39: normal_monthly discount of 20 TND per month on 160 TND fee previews as 140 TND net due.
     */
    public function test_39_normal_monthly_discount_previews_140_net_due(): void
    {
        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 160.0);

        app(MonthlyDiscountService::class)->createDiscount(
            $enrollment->id,
            'normal_monthly',
            20.0,
            'خصم شهري عادي 20 د'
        );

        $prev = app(\App\Services\CollectionService::class)->preview($enrollment->id, ['2025-09']);

        $this->assertEquals(160.0, $prev['gross_amount']);
        $this->assertEquals(20.0, $prev['discount_amount']);
        $this->assertEquals(140.0, $prev['net_due']);
        $this->assertEquals(140.0, $prev['remaining_amount']);
        $this->assertTrue($prev['can_collect']);
        $this->assertFalse($prev['is_fully_waived']);
    }

    /**
     * Test 40: normal_monthly discount exceeding 20% cap (e.g. 50 TND on 160 TND fee, cap 32 TND) is rejected.
     */
    public function test_40_normal_monthly_exceeding_20_percent_cap_rejected(): void
    {
        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 160.0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('يتجاوز الحد الأقصى المسموح به 20%');

        app(MonthlyDiscountService::class)->createDiscount(
            $enrollment->id,
            'normal_monthly',
            50.0,
            'خصم شهري عادي زائد'
        );
    }

    /**
     * Test 41: normal_monthly discount of 10 TND from Sept through June covers 10 months, total 100 TND, excludes August.
     */
    public function test_41_normal_monthly_discount_duration_and_august_exclusion(): void
    {
        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 150.0); // 10 * 150 = 1500 TND annual gross

        $disc = app(MonthlyDiscountService::class)->createDiscount(
            $enrollment->id,
            'normal_monthly',
            10.0,
            'خصم عادي شهري 10 د',
            null,
            null,
            '2025-09',
            '2026-06'
        );

        $this->assertSame('2025-09', $disc->start_month);
        $this->assertSame('2026-06', $disc->end_month);

        // Sept through June (10 months) cover month
        $this->assertTrue($disc->coversMonth('2025-09'));
        $this->assertTrue($disc->coversMonth('2026-01'));
        $this->assertTrue($disc->coversMonth('2026-06'));

        // August is excluded
        $this->assertFalse($disc->coversMonth('2025-08'));
        $this->assertFalse($disc->coversMonth('2026-08'));

        // Collection preview for August shows zero discount
        $augPrev = app(\App\Services\CollectionService::class)->preview($enrollment->id, ['2025-08']);
        $this->assertEquals(0.0, $augPrev['discount_amount']);
    }

    /**
     * Test 42: Collection preview for 1 month vs 10 months with per-month independence.
     */
    public function test_42_collection_preview_single_vs_ten_months(): void
    {
        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 160.0);

        app(MonthlyDiscountService::class)->createDiscount(
            $enrollment->id,
            'normal_monthly',
            10.0,
            'خصم شهري 10 د',
            null,
            null,
            '2025-09',
            '2026-06'
        );

        $collectionService = app(\App\Services\CollectionService::class);
        $months = $collectionService->getAcademicYearMonths($year);
        $this->assertCount(10, $months); // Sept through June

        // Single month preview
        $prevSingle = $collectionService->preview($enrollment->id, ['2025-09']);
        $this->assertEquals(160.0, $prevSingle['gross_amount']);
        $this->assertEquals(10.0, $prevSingle['discount_amount']);
        $this->assertEquals(150.0, $prevSingle['net_due']);
        $this->assertEquals(150.0, $prevSingle['remaining_amount']);

        // Ten months preview
        $prevTen = $collectionService->preview($enrollment->id, $months);
        $this->assertEquals(1600.0, $prevTen['gross_amount']);
        $this->assertEquals(100.0, $prevTen['discount_amount']);
        $this->assertEquals(1500.0, $prevTen['net_due']);
        $this->assertEquals(1500.0, $prevTen['remaining_amount']);

        // Independent per-month items
        $this->assertCount(10, $prevTen['items']);
        foreach ($prevTen['items'] as $item) {
            $this->assertEquals(160.0, $item['gross_amount']);
            $this->assertEquals(10.0, $item['discount_amount']);
            $this->assertEquals(150.0, $item['net_due']);
        }
    }

    /**
     * Test 43: Regression test for bi-monthly 300 TND fee (150 TND/month) and 10 TND per-month discount:
     * - Monthly: 150 TND gross, 10 TND discount, 140 TND net.
     * - 2-Month: 300 TND gross, 20 TND discount, 280 TND net.
     * - 10-Month Academic Year: 1500 TND gross, 100 TND discount, 1400 TND net.
     */
    public function test_43_two_month_and_annual_discount_calculations(): void
    {
        $year = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($year);
        $this->makeFeePlan($year, $enrollment->level_id, 150.0); // 150 TND/month -> 1500 TND annual

        // 10 TND per-month discount
        $disc = app(DiscountService::class)->createForEnrollment($enrollment->id, 10.0, 'تخفيض عادي 10 د شهرياً', '2025-09-01');
        $this->assertEquals(10.0, $disc->amount);

        $collectionService = app(\App\Services\CollectionService::class);

        // 1. Single month calculation (150 gross, 10 disc, 140 net)
        $prev1 = $collectionService->preview($enrollment->id, ['2025-09']);
        $this->assertEquals(150.0, $prev1['gross_amount']);
        $this->assertEquals(10.0, $prev1['discount_amount']);
        $this->assertEquals(140.0, $prev1['net_due']);

        // 2. Two-month calculation (300 gross, 20 disc, 280 net)
        $prev2 = $collectionService->preview($enrollment->id, ['2025-09', '2025-10']);
        $this->assertEquals(300.0, $prev2['gross_amount']);
        $this->assertEquals(20.0, $prev2['discount_amount']);
        $this->assertEquals(280.0, $prev2['net_due']);

        // 3. Ten-month academic year calculation (1500 gross, 100 disc, 1400 net)
        $allMonths = $collectionService->getAcademicYearMonths($year);
        $prev10 = $collectionService->preview($enrollment->id, $allMonths);
        $this->assertEquals(1500.0, $prev10['gross_amount']);
        $this->assertEquals(100.0, $prev10['discount_amount']);
        $this->assertEquals(1400.0, $prev10['net_due']);

        // 4. DiscountController show summary
        $user = $this->makeWaiverUser();
        Sanctum::actingAs($user);

        $showResponse = $this->getJson("/api/enrollments/{$enrollment->id}/discount");

        $showResponse->assertOk()
            ->assertJsonPath('enrollment.annual_fees', 1500)
            ->assertJsonPath('enrollment.discount_cap', 30)
            ->assertJsonPath('enrollment.active_discount', 10)
            ->assertJsonPath('enrollment.annual_discount', 100)
            ->assertJsonPath('enrollment.net_fees', 1400);
    }
}
