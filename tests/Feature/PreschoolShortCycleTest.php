<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Club;
use App\Models\ClubMonthlyFee;
use App\Models\ClubSubscription;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\Level;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\FamilyService;
use App\Services\LedgerService;
use App\Services\PreschoolShortCycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PreschoolShortCycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected AcademicYear $academicYear;
    protected FeeCategory $tuitionCategory;
    protected FeeType $tuitionFeeType;
    protected Level $levelPre1;
    protected Level $levelPre2;
    protected Level $levelPre3;
    protected Level $levelPrimary1;
    protected FeePlan $planPre1;
    protected FeePlan $planPre2;
    protected FeePlan $planPre3;
    protected FeePlan $planPrimary1;
    protected Enrollment $enrollmentPre1;
    protected Enrollment $enrollmentPre2;
    protected Enrollment $enrollmentPre3;
    protected Enrollment $enrollmentPrimary1;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Roles and Permissions
        $permission = Permission::create([
            'name'         => 'manage_payments',
            'display_name' => 'إدارة التحصيل والدفعات',
            'module'       => 'finance',
        ]);

        $reportPermission = Permission::firstOrCreate([
            'name'         => 'view_reports',
            'display_name' => 'عرض التقارير المالية',
            'module'       => 'finance',
        ]);

        $role = Role::create([
            'name'         => 'cashier',
            'display_name' => 'قابض',
        ]);
        $role->permissions()->attach([$permission->id, $reportPermission->id]);

        $this->user = User::create([
            'role_id'    => $role->id,
            'username'   => 'cashier_test',
            'first_name' => 'سامي',
            'last_name'  => 'العياري',
            'email'      => 'cashier_test@school.test',
            'password'   => bcrypt('password'),
            'is_active'  => true,
        ]);

        // 2. Active Academic Year
        $this->academicYear = AcademicYear::create([
            'name'       => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date'   => '2027-06-30',
            'is_active'  => true,
        ]);

        // 3. FeeCategory & FeeType
        $this->tuitionCategory = FeeCategory::create([
            'name'         => 'معاليم التمدرس الشهري',
            'code'         => 'TUITION_MONTHLY',
            'is_recurring' => true,
        ]);

        $this->tuitionFeeType = FeeType::create([
            'fee_category_id' => $this->tuitionCategory->id,
            'name_ar'         => 'معلوم التمدرس الشهري',
            'name_fr'         => 'Frais de scolarité',
            'price'           => 100.00,
            'is_active'       => true,
        ]);

        // 4. Levels
        $this->levelPre1 = Level::create(['name' => 'روضة', 'code' => 'PRE1', 'order' => 1]);
        $this->levelPre2 = Level::create(['name' => 'تمهيدي', 'code' => 'PRE2', 'order' => 2]);
        $this->levelPre3 = Level::create(['name' => 'تحضيري', 'code' => 'PRE3', 'order' => 3]);
        $this->levelPrimary1 = Level::create(['name' => 'السنة الأولى', 'code' => 'L1', 'order' => 4]);

        // 5. Sections
        $secPre1 = Section::create(['name' => 'فوج الروضة', 'code' => 'PRE1-A', 'level_id' => $this->levelPre1->id]);
        $secPre2 = Section::create(['name' => 'فوج التمهيدي', 'code' => 'PRE2-A', 'level_id' => $this->levelPre2->id]);
        $secPre3 = Section::create(['name' => 'فوج التحضيري', 'code' => 'PRE3-A', 'level_id' => $this->levelPre3->id]);
        $secL1 = Section::create(['name' => 'أولى أ', 'code' => 'L1-A', 'level_id' => $this->levelPrimary1->id]);

        // 6. FeePlans (Official Master Data)
        $this->planPre1 = FeePlan::create([
            'academic_year_id' => $this->academicYear->id,
            'level_id'         => $this->levelPre1->id,
            'fee_category_id'  => $this->tuitionCategory->id,
            'name'             => 'القسط الشهري — الروضة',
            'amount'           => 100.00,
            'frequency'        => 'monthly',
            'due_day'          => 1,
        ]);

        $this->planPre2 = FeePlan::create([
            'academic_year_id' => $this->academicYear->id,
            'level_id'         => $this->levelPre2->id,
            'fee_category_id'  => $this->tuitionCategory->id,
            'name'             => 'القسط الشهري — التمهيدي',
            'amount'           => 100.00,
            'frequency'        => 'monthly',
            'due_day'          => 1,
        ]);

        $this->planPre3 = FeePlan::create([
            'academic_year_id' => $this->academicYear->id,
            'level_id'         => $this->levelPre3->id,
            'fee_category_id'  => $this->tuitionCategory->id,
            'name'             => 'القسط الشهري — التحضيري',
            'amount'           => 120.00,
            'frequency'        => 'monthly',
            'due_day'          => 1,
        ]);

        $this->planPrimary1 = FeePlan::create([
            'academic_year_id' => $this->academicYear->id,
            'level_id'         => $this->levelPrimary1->id,
            'fee_category_id'  => $this->tuitionCategory->id,
            'name'             => 'القسط الشهري — الابتدائي',
            'amount'           => 150.00,
            'frequency'        => 'monthly',
            'due_day'          => 1,
        ]);

        // 7. Students & Enrollments
        $student1 = Student::create([
            'student_code' => 'PRV-PRE1-001',
            'first_name'   => 'يوسف',
            'last_name'    => 'الطرابلسي',
            'gender'       => 'male',
            'guardian_phone' => '98111222',
            'status'       => 'active',
        ]);
        $this->enrollmentPre1 = Enrollment::create([
            'student_id'       => $student1->id,
            'academic_year_id' => $this->academicYear->id,
            'level_id'         => $this->levelPre1->id,
            'section_id'       => $secPre1->id,
            'enrollment_date'  => '2026-09-01',
            'status'           => 'active',
        ]);

        $student2 = Student::create([
            'student_code' => 'PRV-PRE2-001',
            'first_name'   => 'سارة',
            'last_name'    => 'الطرابلسي',
            'gender'       => 'female',
            'guardian_phone' => '98111222',
            'status'       => 'active',
        ]);
        $this->enrollmentPre2 = Enrollment::create([
            'student_id'       => $student2->id,
            'academic_year_id' => $this->academicYear->id,
            'level_id'         => $this->levelPre2->id,
            'section_id'       => $secPre2->id,
            'enrollment_date'  => '2026-09-01',
            'status'           => 'active',
        ]);

        $student3 = Student::create([
            'student_code' => 'PRV-PRE3-001',
            'first_name'   => 'مريم',
            'last_name'    => 'الجزيري',
            'gender'       => 'female',
            'guardian_phone' => '98333444',
            'status'       => 'active',
        ]);
        $this->enrollmentPre3 = Enrollment::create([
            'student_id'       => $student3->id,
            'academic_year_id' => $this->academicYear->id,
            'level_id'         => $this->levelPre3->id,
            'section_id'       => $secPre3->id,
            'enrollment_date'  => '2026-09-01',
            'status'           => 'active',
        ]);

        $studentL1 = Student::create([
            'student_code' => 'PRV-L1-001',
            'first_name'   => 'خليل',
            'last_name'    => 'الرياحي',
            'gender'       => 'male',
            'status'       => 'active',
        ]);
        $this->enrollmentPrimary1 = Enrollment::create([
            'student_id'       => $studentL1->id,
            'academic_year_id' => $this->academicYear->id,
            'level_id'         => $this->levelPrimary1->id,
            'section_id'       => $secL1->id,
            'enrollment_date'  => '2026-09-01',
            'status'           => 'active',
        ]);
    }

    /** 1. PRE1 half سبتمبر = 50، رسم واحد، allocation واحد، monthly_fee */
    public function test_01_pre1_half_rate_september_creates_fee_and_payment_of_50_tnd(): void
    {
        $payload = [
            'enrollment_id'   => $this->enrollmentPre1->id,
            'payment_date'    => '2026-09-15',
            'method'          => 'cash',
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2026-09',
        ];

        $response = $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('amount', 50)
            ->assertJsonPath('months', ['2026-09']);

        $this->assertDatabaseHas('payments', [
            'enrollment_id' => $this->enrollmentPre1->id,
            'amount'        => 50.00,
        ]);

        $fee = StudentFee::where('enrollment_id', $this->enrollmentPre1->id)->first();
        $this->assertNotNull($fee);
        $this->assertEquals($this->planPre1->id, $fee->fee_plan_id);
        $this->assertEquals(50.00, (float) $fee->amount_due);
        $this->assertEquals('paid', $fee->status);
        $this->assertEquals('2026-09-01', $fee->due_date->format('Y-m-d'));

        $this->assertDatabaseCount('student_fees', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseCount('cash_transactions', 1);

        $tx = CashTransaction::first();
        $this->assertEquals(CashTransaction::CATEGORY_MONTHLY_FEE, $tx->category);
        $this->assertEquals(50.00, (float) $tx->amount);
        $this->assertEquals(CashTransaction::DIRECTION_IN, $tx->direction);
    }

    /** 2. PRE1 half جوان = 50 */
    public function test_02_pre1_half_rate_june_creates_fee_and_payment_of_50_tnd(): void
    {
        $payload = [
            'enrollment_id'   => $this->enrollmentPre1->id,
            'payment_date'    => '2026-09-15',
            'method'          => 'cash',
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2027-06',
        ];

        $response = $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('amount', 50)
            ->assertJsonPath('months', ['2027-06']);

        $fee = StudentFee::where('enrollment_id', $this->enrollmentPre1->id)->first();
        $this->assertNotNull($fee);
        $this->assertEquals($this->planPre1->id, $fee->fee_plan_id);
        $this->assertEquals(50.00, (float) $fee->amount_due);
        $this->assertEquals('paid', $fee->status);
        $this->assertEquals('2027-06-01', $fee->due_date->format('Y-m-d'));

        $tx = CashTransaction::first();
        $this->assertEquals(CashTransaction::CATEGORY_MONTHLY_FEE, $tx->category);
        $this->assertEquals(50.00, (float) $tx->amount);
    }

    /** 3. PRE1 full = 100، رسمان، تخصيصان، قيد واحد monthly_fee */
    public function test_03_pre1_full_rate_creates_two_fees_and_single_payment_of_100_tnd(): void
    {
        $payload = [
            'enrollment_id' => $this->enrollmentPre1->id,
            'payment_date'  => '2026-09-15',
            'method'        => 'cash',
            'cycle_mode'    => 'full_rate',
        ];

        $response = $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('amount', 100)
            ->assertJsonPath('months', ['2026-09', '2027-06']);

        $this->assertDatabaseCount('student_fees', 2);
        $this->assertDatabaseCount('payment_allocations', 2);
        $this->assertDatabaseCount('cash_transactions', 1);

        $tx = CashTransaction::first();
        $this->assertEquals(CashTransaction::CATEGORY_MONTHLY_FEE, $tx->category);
        $this->assertEquals(100.00, (float) $tx->amount);
    }

    /** 4. PRE3 full = 120، رسمان × 60، قيد واحد monthly_fee */
    public function test_04_pre3_full_rate_creates_two_fees_of_60_and_single_payment_of_120_tnd(): void
    {
        $payload = [
            'enrollment_id' => $this->enrollmentPre3->id,
            'payment_date'  => '2026-09-15',
            'method'        => 'cash',
            'cycle_mode'    => 'full_rate',
        ];

        $response = $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('amount', 120);

        $fees = StudentFee::where('enrollment_id', $this->enrollmentPre3->id)->get();
        $this->assertCount(2, $fees);
        foreach ($fees as $f) {
            $this->assertEquals(60.00, (float) $f->amount_due);
            $this->assertEquals('paid', $f->status);
        }

        $tx = CashTransaction::first();
        $this->assertEquals(CashTransaction::CATEGORY_MONTHLY_FEE, $tx->category);
        $this->assertEquals(120.00, (float) $tx->amount);
    }

    /** 5. مستوى غير PRE → رفض */
    public function test_05_non_preschool_level_is_strictly_rejected(): void
    {
        $payload = [
            'enrollment_id' => $this->enrollmentPrimary1->id,
            'payment_date'  => '2026-09-15',
            'method'        => 'cash',
            'cycle_mode'    => 'full_rate',
        ];

        $response = $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', $payload);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'هذا المسار مخصص حصراً لأقسام الروضة والتمهيدي والتحضيري (PRE1, PRE2, PRE3).']);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('cash_transactions', 0);
    }

    /** 6. سنة غير نشطة → رفض */
    public function test_06_inactive_academic_year_is_rejected(): void
    {
        $this->academicYear->update(['is_active' => false]);

        $payload = [
            'enrollment_id' => $this->enrollmentPre1->id,
            'payment_date'  => '2026-09-15',
            'method'        => 'cash',
            'cycle_mode'    => 'full_rate',
        ];

        $response = $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', $payload);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'السنة الدراسية للتسجيل غير نشطة.']);
    }

    /** 7. أكثر من FeePlan شهري للمستوى → رفض */
    public function test_07_multiple_fee_plans_for_level_is_rejected(): void
    {
        // Add a conflicting second monthly plan
        FeePlan::create([
            'academic_year_id' => $this->academicYear->id,
            'level_id'         => $this->levelPre1->id,
            'fee_category_id'  => $this->tuitionCategory->id,
            'name'             => 'القسط الشهري الثاني المتعارض',
            'amount'           => 110.00,
            'frequency'        => 'monthly',
            'due_day'          => 1,
        ]);

        $payload = [
            'enrollment_id' => $this->enrollmentPre1->id,
            'payment_date'  => '2026-09-15',
            'method'        => 'cash',
            'cycle_mode'    => 'full_rate',
        ];

        $response = $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', $payload);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'يجب توفر خطة رسوم شهرية رسمية واحدة ومحددة لهذا المستوى.']);
    }

    /** 8. half_rate لشهر غير سبتمبر/جوان → رفض */
    public function test_08_half_rate_for_invalid_month_is_rejected(): void
    {
        $payload = [
            'enrollment_id'   => $this->enrollmentPre1->id,
            'payment_date'    => '2026-09-15',
            'method'          => 'cash',
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2026-10', // October not supported
        ];

        $response = $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', $payload);

        $response->assertStatus(422);
    }

    /** 9. full_rate إذا سبتمبر مدفوع → رفض */
    public function test_09_full_rate_rejected_if_september_already_paid(): void
    {
        // Pay September first
        $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', [
            'enrollment_id'   => $this->enrollmentPre1->id,
            'payment_date'    => '2026-09-15',
            'method'          => 'cash',
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2026-09',
        ])->assertStatus(201);

        // Attempt full rate
        $response = $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', [
            'enrollment_id' => $this->enrollmentPre1->id,
            'payment_date'  => '2026-09-16',
            'method'        => 'cash',
            'cycle_mode'    => 'full_rate',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'شهر سبتمبر مستخلص مسبقاً؛ يرجى اختيار نصف معلوم لشهر جوان.']);
    }

    /** 10. full_rate إذا جوان مدفوع → رفض */
    public function test_10_full_rate_rejected_if_june_already_paid(): void
    {
        // Pay June first
        $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', [
            'enrollment_id'   => $this->enrollmentPre1->id,
            'payment_date'    => '2026-09-15',
            'method'          => 'cash',
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2027-06',
        ])->assertStatus(201);

        // Attempt full rate
        $response = $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', [
            'enrollment_id' => $this->enrollmentPre1->id,
            'payment_date'  => '2026-09-16',
            'method'        => 'cash',
            'cycle_mode'    => 'full_rate',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'شهر جوان مستخلص مسبقاً؛ يرجى اختيار نصف معلوم لشهر سبتمبر.']);
    }

    /** 11. half_rate إذا الشهر مدفوع → رفض */
    public function test_11_half_rate_rejected_if_selected_month_already_paid(): void
    {
        // Pay September
        $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', [
            'enrollment_id'   => $this->enrollmentPre1->id,
            'payment_date'    => '2026-09-15',
            'method'          => 'cash',
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2026-09',
        ])->assertStatus(201);

        // Pay September again
        $response = $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', [
            'enrollment_id'   => $this->enrollmentPre1->id,
            'payment_date'    => '2026-09-16',
            'method'          => 'cash',
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2026-09',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'شهر سبتمبر مستخلص مسبقاً لهذا التلميذ.']);
    }

    /** 12. فئة Ledger صحيحة: monthly_fee */
    public function test_12_ledger_category_is_strictly_monthly_fee(): void
    {
        $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', [
            'enrollment_id' => $this->enrollmentPre1->id,
            'payment_date'  => '2026-09-15',
            'method'        => 'cash',
            'cycle_mode'    => 'full_rate',
        ])->assertStatus(201);

        $tx = CashTransaction::where('category', CashTransaction::CATEGORY_MONTHLY_FEE)->first();
        $this->assertNotNull($tx);
        $this->assertEquals(100.00, (float) $tx->amount);
        $this->assertNull(CashTransaction::where('category', CashTransaction::CATEGORY_OTHER_INCOME)->first());
    }

    /** 13. فشل Ledger أو category خاطئة → rollback */
    public function test_13_ledger_verification_failure_triggers_complete_rollback(): void
    {
        // Mock LedgerService to throw or simulate wrong category by hacking
        $mockLedger = $this->createMock(LedgerService::class);
        $mockLedger->expects($this->once())
            ->method('recordPayment')
            ->willThrowException(new \DomainException('Ledger system network failure'));

        $this->app->instance(LedgerService::class, $mockLedger);

        $response = $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', [
            'enrollment_id' => $this->enrollmentPre1->id,
            'payment_date'  => '2026-09-15',
            'method'        => 'cash',
            'cycle_mode'    => 'full_rate',
        ]);

        $response->assertStatus(422);

        // Assert clean rollback
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('student_fees', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('cash_transactions', 0);
    }

    /** 14. Payment amount = total allocations */
    public function test_14_payment_amount_strictly_equals_sum_of_allocations(): void
    {
        $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', [
            'enrollment_id' => $this->enrollmentPre3->id,
            'payment_date'  => '2026-09-15',
            'method'        => 'cash',
            'cycle_mode'    => 'full_rate',
        ])->assertStatus(201);

        $payment = Payment::first();
        $allocSum = (float) $payment->paymentAllocations()->sum('amount_allocated');

        $this->assertEquals((float) $payment->amount, $allocSum);
        $this->assertEquals(120.00, (float) $payment->amount);
    }

    /** 15. نفس idempotency key → Payment/CashTransaction واحدة فقط */
    public function test_15_same_idempotency_key_does_not_create_duplicate_payment_or_cash_transaction(): void
    {
        $payload = [
            'enrollment_id'   => $this->enrollmentPre1->id,
            'payment_date'    => '2026-09-15',
            'method'          => 'cash',
            'cycle_mode'      => 'full_rate',
            'idempotency_key' => 'IDEMP-PRE-TEST-999',
        ];

        // First call
        $res1 = $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', $payload);
        $res1->assertStatus(201);

        // Second call with identical idempotency key
        $res2 = $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', $payload);
        $res2->assertStatus(201);

        $this->assertEquals($res1->json('payment_id'), $res2->json('payment_id'));
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('cash_transactions', 1);
    }

    /** 16. Cancellation half: يعكس القيد ويفتح الشهر المستهدف حسب السلوك الحالي */
    public function test_16_cancellation_of_half_rate_reopens_only_the_selected_month(): void
    {
        $res = $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', [
            'enrollment_id'   => $this->enrollmentPre1->id,
            'payment_date'    => '2026-09-15',
            'method'          => 'cash',
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2026-09',
        ]);
        $paymentId = $res->json('payment_id');

        // Cancel the payment via PaymentController
        $cancelRes = $this->actingAs($this->user)->postJson("/api/payments/{$paymentId}/cancel", [
            'reason' => 'طلب الولي إلغاء الوصل بالخطأ',
        ]);
        $cancelRes->assertStatus(200);

        $fee = StudentFee::first();
        $this->assertEquals('pending', $fee->status);

        // Cash transaction must be cancelled
        $tx = CashTransaction::where('source_id', $paymentId)->first();
        $this->assertNotNull($tx->cancelled_at);

        // Verify month is now open and can be collected again
        $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', [
            'enrollment_id'   => $this->enrollmentPre1->id,
            'payment_date'    => '2026-09-16',
            'method'          => 'cash',
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2026-09',
        ])->assertStatus(201);
    }

    /** 17. Cancellation full: يعكس القيد ويفتح الشهرين حسب السلوك الحالي */
    public function test_17_cancellation_of_full_rate_reopens_both_months(): void
    {
        $res = $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', [
            'enrollment_id' => $this->enrollmentPre1->id,
            'payment_date'  => '2026-09-15',
            'method'        => 'cash',
            'cycle_mode'    => 'full_rate',
        ]);
        $paymentId = $res->json('payment_id');

        // Cancel
        $this->actingAs($this->user)->postJson("/api/payments/{$paymentId}/cancel", [
            'reason' => 'إلغاء دفعة كاملة',
        ])->assertStatus(200);

        $fees = StudentFee::all();
        $this->assertCount(2, $fees);
        foreach ($fees as $f) {
            $this->assertEquals('pending', $f->status);
        }

        // Full collection can be run again
        $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', [
            'enrollment_id' => $this->enrollmentPre1->id,
            'payment_date'  => '2026-09-16',
            'method'        => 'cash',
            'cycle_mode'    => 'full_rate',
        ])->assertStatus(201);

        $this->assertDatabaseCount('student_fees', 2); // No new fee rows created; existing pending rows reused!
    }

    /** 18. لا علاقة بسجلات النادي الحالية */
    public function test_18_existing_club_fees_with_club_monthly_fee_id_are_never_touched(): void
    {
        // Create a historical club fee on the same student
        $club = Club::create(['name' => 'نادي الروبوتيك', 'fee_category_id' => $this->tuitionCategory->id, 'monthly_fee' => 20, 'is_active' => true]);
        $sub = ClubSubscription::create([
            'student_id'       => $this->enrollmentPre1->student_id,
            'club_id'          => $club->id,
            'academic_year_id' => $this->academicYear->id,
            'enrollment_id'    => $this->enrollmentPre1->id,
            'start_date'       => '2026-09-01',
            'status'           => 'active',
        ]);
        $cmf = ClubMonthlyFee::create([
            'student_id'           => $this->enrollmentPre1->student_id,
            'club_id'              => $club->id,
            'academic_year_id'     => $this->academicYear->id,
            'enrollment_id'        => $this->enrollmentPre1->id,
            'club_subscription_id' => $sub->id,
            'month'                => '2026-09',
            'amount_due'           => 20.00,
            'amount_paid'          => 0,
            'status'               => 'unpaid',
        ]);
        $clubFee = StudentFee::create([
            'enrollment_id'       => $this->enrollmentPre1->id,
            'club_monthly_fee_id' => $cmf->id,
            'fee_plan_id'         => null,
            'fee_type_id'         => null,
            'amount_due'          => 20.00,
            'direct_paid_amount'  => 0,
            'due_date'            => '2026-09-01',
            'description'         => 'معلوم نادي الروبوتيك — 2026-09',
            'status'              => 'pending',
        ]);

        // Run full short cycle
        $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', [
            'enrollment_id' => $this->enrollmentPre1->id,
            'payment_date'  => '2026-09-15',
            'method'        => 'cash',
            'cycle_mode'    => 'full_rate',
        ])->assertStatus(201);

        // Club fee must remain untouched and pending!
        $clubFee->refresh();
        $this->assertEquals('pending', $clubFee->status);
        $this->assertEquals(0, (float) $clubFee->direct_paid_amount);
        $this->assertEquals(0, $clubFee->paymentAllocations()->count());
    }

    /** 19. لا يمس prior_year_debt / old_liability_collection / employee advances */
    public function test_19_prior_year_debts_and_advances_are_never_affected(): void
    {
        $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', [
            'enrollment_id' => $this->enrollmentPre1->id,
            'payment_date'  => '2026-09-15',
            'method'        => 'cash',
            'cycle_mode'    => 'full_rate',
        ])->assertStatus(201);

        $this->assertNull(CashTransaction::where('category', CashTransaction::CATEGORY_PRIOR_YEAR_DEBT)->first());
        $this->assertNull(CashTransaction::where('category', CashTransaction::CATEGORY_OLD_LIABILITY_COLLECTION)->first());
        $this->assertNull(CashTransaction::where('category', CashTransaction::CATEGORY_EMPLOYEE_ADVANCE)->first());
    }

    /** 20. Regression لمسارات التحصيل الحالية + Preview test */
    public function test_20_preview_endpoint_returns_accurate_state_and_pricing(): void
    {
        $previewRes = $this->actingAs($this->user)->getJson("/api/collections/preschool-short-cycle/preview/{$this->enrollmentPre1->id}");

        $previewRes->assertStatus(200)
            ->assertJsonPath('is_preschool', true)
            ->assertJsonPath('full_rate', 100)
            ->assertJsonPath('half_rate', 50)
            ->assertJsonPath('can_collect_full', true)
            ->assertJsonPath('can_collect_september', true)
            ->assertJsonPath('can_collect_june', true);
    }

    /** 21. PRE2 half سبتمبر = 50، رسم واحد، دفعة 50، monthly_fee */
    public function test_21_pre2_half_rate_september_creates_fee_and_payment_of_50_tnd(): void
    {
        $payload = [
            'enrollment_id'   => $this->enrollmentPre2->id,
            'payment_date'    => '2026-09-15',
            'method'          => 'cash',
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2026-09',
        ];

        $response = $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('amount', 50)
            ->assertJsonPath('months', ['2026-09']);

        $fee = StudentFee::where('enrollment_id', $this->enrollmentPre2->id)->first();
        $this->assertNotNull($fee);
        $this->assertEquals(50.00, (float) $fee->amount_due);
        $this->assertEquals(50.00, (float) $fee->paymentAllocations()->sum('amount_allocated'));
        $this->assertEquals('paid', $fee->status);
        $this->assertEquals('2026-09-01', $fee->due_date->format('Y-m-d'));

        $payment = Payment::first();
        $this->assertNotNull($payment);
        $this->assertEquals(50.00, (float) $payment->amount);
        $this->assertEquals(['2026-09'], $payment->months);

        $tx = CashTransaction::first();
        $this->assertEquals(CashTransaction::CATEGORY_MONTHLY_FEE, $tx->category);
        $this->assertEquals(50.00, (float) $tx->amount);
    }

    /** 22. PRE2 half جوان = 50، رسم واحد، دفعة 50، monthly_fee */
    public function test_22_pre2_half_rate_june_creates_fee_and_payment_of_50_tnd(): void
    {
        $payload = [
            'enrollment_id'   => $this->enrollmentPre2->id,
            'payment_date'    => '2026-09-15',
            'method'          => 'cash',
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2027-06',
        ];

        $response = $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('amount', 50)
            ->assertJsonPath('months', ['2027-06']);

        $fee = StudentFee::where('enrollment_id', $this->enrollmentPre2->id)->first();
        $this->assertNotNull($fee);
        $this->assertEquals(50.00, (float) $fee->amount_due);
        $this->assertEquals(50.00, (float) $fee->paymentAllocations()->sum('amount_allocated'));
        $this->assertEquals('paid', $fee->status);
        $this->assertEquals('2027-06-01', $fee->due_date->format('Y-m-d'));

        $payment = Payment::first();
        $this->assertEquals(50.00, (float) $payment->amount);
        $this->assertEquals(['2027-06'], $payment->months);
    }

    /** 23. PRE2 full سبتمبر + جوان = 100، رسمان × 50، دفعة 100 */
    public function test_23_pre2_full_rate_creates_two_fees_and_payment_of_100_tnd(): void
    {
        $payload = [
            'enrollment_id' => $this->enrollmentPre2->id,
            'payment_date'  => '2026-09-15',
            'method'        => 'cash',
            'cycle_mode'    => 'full_rate',
        ];

        $response = $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('amount', 100);

        $fees = StudentFee::where('enrollment_id', $this->enrollmentPre2->id)->get();
        $this->assertCount(2, $fees);
        foreach ($fees as $f) {
            $this->assertEquals(50.00, (float) $f->amount_due);
            $this->assertEquals('paid', $f->status);
        }

        $payment = Payment::first();
        $this->assertEquals(100.00, (float) $payment->amount);
        $this->assertEquals(['2026-09', '2027-06'], $payment->months);

        $tx = CashTransaction::first();
        $this->assertEquals(CashTransaction::CATEGORY_MONTHLY_FEE, $tx->category);
        $this->assertEquals(100.00, (float) $tx->amount);
    }

    /** 24. قبول سبتمبر في المسار القياسي بمبلغ يدوي لأقسام ما قبل المدرسي */
    public function test_24_standard_collection_accepts_september_with_manual_amount_for_pre1(): void
    {
        // استخلاص سبتمبر لتلميذ PRE1 بمبلغ يدوي 40 د.ت
        $collectRes = $this->actingAs($this->user)->postJson('/api/payments/collect', [
            'student_id'    => $this->enrollmentPre1->student_id,
            'enrollment_id' => $this->enrollmentPre1->id,
            'months'        => ['2026-09'],
            'payment_date'  => '2026-09-15',
            'method'        => 'cash',
            'manual_amount' => 40.00,
            'items'         => [
                ['fee_type_id' => $this->tuitionFeeType->id, 'amount' => 40.00],
            ],
        ]);

        $collectRes->assertStatus(201);

        $fee = StudentFee::where('enrollment_id', $this->enrollmentPre1->id)->first();
        $this->assertNotNull($fee);
        $this->assertEquals(40.00, (float) $fee->amount_due);
        $this->assertEquals(40.00, (float) $fee->paymentAllocations()->sum('amount_allocated'));
        $this->assertEquals('paid', $fee->status);

        $payment = Payment::where('enrollment_id', $this->enrollmentPre1->id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals(40.00, (float) $payment->amount);

        $tx = CashTransaction::where('source_type', Payment::class)->where('source_id', $payment->id)->first();
        $this->assertNotNull($tx);
        $this->assertEquals(40.00, (float) $tx->amount);
    }

    /** 25. وضوح الاستخلاص في getPaidMonths و monthLedger بعد الاستخلاص النصفي والكامل */
    public function test_25_visibility_get_paid_months_and_ledger_after_half_and_full_collection(): void
    {
        $service = app(CollectionService::class);

        // قبل الاستخلاص: غير مستخلصين
        $this->assertNotContains('2026-09', $service->getPaidMonths($this->enrollmentPre1->id));
        $this->assertNotContains('2027-06', $service->getPaidMonths($this->enrollmentPre1->id));

        $ledgerBefore = $service->monthLedger($this->enrollmentPre1->id);
        $this->assertArrayNotHasKey('2026-09', $ledgerBefore);
        $this->assertArrayNotHasKey('2027-06', $ledgerBefore);

        // 1. استخلاص نصف شهر (سبتمبر)
        $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', [
            'enrollment_id'   => $this->enrollmentPre1->id,
            'payment_date'    => '2026-09-15',
            'method'          => 'cash',
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2026-09',
        ])->assertStatus(201);

        // التحقق من ظهور سبتمبر مستخلصاً وجوان غير مستخلص
        $paidAfterHalf = $service->getPaidMonths($this->enrollmentPre1->id);
        $this->assertContains('2026-09', $paidAfterHalf);
        $this->assertNotContains('2027-06', $paidAfterHalf);

        $ledgerAfterHalf = $service->monthLedger($this->enrollmentPre1->id);
        $this->assertArrayHasKey('2026-09', $ledgerAfterHalf);
        $this->assertEquals(50.00, (float) $ledgerAfterHalf['2026-09'][0]['amount']);
        $this->assertArrayNotHasKey('2027-06', $ledgerAfterHalf);

        // فحص مسار API ledger أيضاً
        $apiLedger = $this->actingAs($this->user)->getJson("/api/enrollments/{$this->enrollmentPre1->id}/ledger");
        $apiLedger->assertStatus(200)
            ->assertJsonPath('paid_months', ['2026-09']);

        // 2. استخلاص النصف الثاني (جوان)
        $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', [
            'enrollment_id'   => $this->enrollmentPre1->id,
            'payment_date'    => '2026-09-16',
            'method'          => 'cash',
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2027-06',
        ])->assertStatus(201);

        $paidAfterFull = $service->getPaidMonths($this->enrollmentPre1->id);
        $this->assertContains('2026-09', $paidAfterFull);
        $this->assertContains('2027-06', $paidAfterFull);

        $ledgerAfterFull = $service->monthLedger($this->enrollmentPre1->id);
        $this->assertArrayHasKey('2026-09', $ledgerAfterFull);
        $this->assertArrayHasKey('2027-06', $ledgerAfterFull);
        $this->assertEquals(50.00, (float) $ledgerAfterFull['2027-06'][0]['amount']);
    }

    /** 26. استبعاد التلميذ تلقائياً من تقرير المتخلفين عن السداد بعد استخلاص الدورة المبسطة */
    public function test_26_unpaid_monthly_report_excludes_students_after_preschool_short_cycle_collection(): void
    {
        // قبل الاستخلاص: التلميذ يظهر في قائمة غير المسددين لشهر سبتمبر
        $beforeRes = $this->actingAs($this->user)->getJson(
            "/api/reports/unpaid-monthly?academic_year_id={$this->academicYear->id}&month=2026-09&section_id={$this->enrollmentPre1->section_id}"
        );
        $beforeRes->assertStatus(200);
        $rowsBefore = collect($beforeRes->json('rows'));
        $this->assertTrue($rowsBefore->contains('student_id', $this->enrollmentPre1->student_id));

        // استخلاص شهر سبتمبر بالدورة المبسطة
        $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', [
            'enrollment_id'   => $this->enrollmentPre1->id,
            'payment_date'    => '2026-09-15',
            'method'          => 'cash',
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2026-09',
        ])->assertStatus(201);

        // بعد الاستخلاص: يجب أن يختفي التلميذ تلقائياً من تقرير سبتمبر
        $afterRes = $this->actingAs($this->user)->getJson(
            "/api/reports/unpaid-monthly?academic_year_id={$this->academicYear->id}&month=2026-09&section_id={$this->enrollmentPre1->section_id}"
        );
        $afterRes->assertStatus(200);
        $rowsAfter = collect($afterRes->json('rows'));
        $this->assertFalse($rowsAfter->contains('student_id', $this->enrollmentPre1->student_id));
    }

    /** 27. انعكاس معاليم ما قبل الابتدائي بنصف المعلوم (50/60) في قائمة العائلات وتفاصيلها دون ديون وهمية */
    public function test_27_family_lists_and_details_reflect_half_rate_debt_and_remaining_for_preschool(): void
    {
        Carbon::setTestNow('2026-09-20');

        try {
            $familyService = app(FamilyService::class);

            // 1. قبل الاستخلاص: تلميذ الروضة (PRE1) معلومه 100، نصف المعلوم 50
            // دين شهر سبتمبر الحالي يجب أن يكون 50.0 د.ت وليس 100.0 د.ت
            $listBefore = $familyService->listFamilies(null, 100, 1);
            $familyBefore = collect($listBefore['data'])->firstWhere('phone', '98111222');
            $this->assertNotNull($familyBefore);

            $pre1StudentBefore = collect($familyBefore['students'])->firstWhere('id', $this->enrollmentPre1->student_id);
            $this->assertNotNull($pre1StudentBefore);
            $this->assertEqualsWithDelta(50.0, (float) $pre1StudentBefore['remaining_debt'], 0.001);

            // تفاصيل العائلة: شبكة الأشهر تعرض شهر سبتمبر بنصف المعلوم 50 د.ت
            $detailsBefore = $familyService->getFamilyDetails('98111222');
            $pre1Details = collect($detailsBefore['students'])->firstWhere('student_id', $this->enrollmentPre1->student_id);
            $septMonth = collect($pre1Details['months_grid'])->firstWhere('month', '2026-09');
            $this->assertEquals(50.0, (float) $septMonth['net_amount']);
            $this->assertEquals('unpaid', $septMonth['status']);

            // جوان أيضاً معلومه 50 د.ت
            $juneMonth = collect($pre1Details['months_grid'])->firstWhere('month', '2027-06');
            $this->assertEquals(50.0, (float) $juneMonth['net_amount']);

            // 2. استخلاص شهر سبتمبر للدورة المبسطة (50 د.ت)
            $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', [
                'enrollment_id'   => $this->enrollmentPre1->id,
                'payment_date'    => '2026-09-20',
                'method'          => 'cash',
                'cycle_mode'      => 'half_rate',
                'half_rate_month' => '2026-09',
            ])->assertStatus(201);

            // بعد استخلاص سبتمبر في شهر سبتمبر: المتبقي على تلميذ PRE1 يصبح 0
            $listAfterSept = $familyService->listFamilies(null, 100, 1);
            $familyAfterSept = collect($listAfterSept['data'])->firstWhere('phone', '98111222');
            $pre1StudentAfterSept = collect($familyAfterSept['students'])->firstWhere('id', $this->enrollmentPre1->student_id);
            $this->assertEqualsWithDelta(0.0, (float) ($pre1StudentAfterSept['remaining_debt'] ?? 0), 0.001);

            // تفاصيل العائلة: سبتمبر أصبح مسدداً بـ 50 د.ت
            $detailsAfterSept = $familyService->getFamilyDetails('98111222');
            $pre1DetailsAfterSept = collect($detailsAfterSept['students'])->firstWhere('student_id', $this->enrollmentPre1->student_id);
            $septMonthAfter = collect($pre1DetailsAfterSept['months_grid'])->firstWhere('month', '2026-09');
            $this->assertEquals('paid', $septMonthAfter['status']);
            $this->assertEquals(50.0, (float) $septMonthAfter['paid_amount']);

            // 3. في جوان (2027-06): يحل استحقاق جوان (50 د.ت) مع بقية أشهر السنة (8 × 100) = 850 د.ت
            // (قبل الإصلاح كانت تُحسب بـ 900 د.ت لاحتساب جوان بـ 100 د.ت كاملة)
            Carbon::setTestNow('2027-06-15');
            $listInJune = $familyService->listFamilies(null, 100, 1);
            $familyInJune = collect($listInJune['data'])->firstWhere('phone', '98111222');
            $pre1StudentInJune = collect($familyInJune['students'])->firstWhere('id', $this->enrollmentPre1->student_id);
            $this->assertEqualsWithDelta(850.0, (float) $pre1StudentInJune['remaining_debt'], 0.001);

            // استخلاص جوان بالدورة المبسطة (50 د.ت)
            $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', [
                'enrollment_id'   => $this->enrollmentPre1->id,
                'payment_date'    => '2027-06-15',
                'method'          => 'cash',
                'cycle_mode'      => 'half_rate',
                'half_rate_month' => '2027-06',
            ])->assertStatus(201);

            // بعد استخلاص جوان: يتبقى فقط الأشهر العادية (أكتوبر إلى ماي: 8 × 100 = 800)
            $listFinal = $familyService->listFamilies(null, 100, 1);
            $familyFinal = collect($listFinal['data'])->firstWhere('phone', '98111222');
            $pre1StudentFinal = collect($familyFinal['students'] ?? [])->firstWhere('id', $this->enrollmentPre1->student_id);
            $this->assertEqualsWithDelta(800.0, (float) ($pre1StudentFinal['remaining_debt'] ?? 0), 0.001);
        } finally {
            Carbon::setTestNow(null);
        }
    }

    /** 28. قبول الابتدائي في المسار القياسي ورفضه في مسار الدورة المبسطة */
    public function test_28_primary_level_accepted_in_standard_collection_and_rejected_in_short_cycle(): void
    {
        // 1. الابتدائي مقبول في المسار القياسي لشهر سبتمبر بالمعلوم الكامل (150 د.ت)
        $collectRes = $this->actingAs($this->user)->postJson('/api/payments/collect', [
            'student_id'    => $this->enrollmentPrimary1->student_id,
            'enrollment_id' => $this->enrollmentPrimary1->id,
            'months'        => ['2026-09'],
            'payment_date'  => '2026-09-15',
            'method'        => 'cash',
            'items'         => [
                ['fee_type_id' => $this->tuitionFeeType->id, 'amount' => 150.00],
            ],
        ]);

        $collectRes->assertStatus(201);

        $fee = StudentFee::where('enrollment_id', $this->enrollmentPrimary1->id)->first();
        $this->assertNotNull($fee);
        $this->assertEquals(150.00, (float) $fee->amount_due);
        $this->assertEquals(150.00, (float) $fee->paymentAllocations()->sum('amount_allocated'));
        $this->assertEquals('paid', $fee->status);

        // 2. الابتدائي مرفوض تماماً في مسار الدورة المبسطة
        $shortCycleRes = $this->actingAs($this->user)->postJson('/api/collections/preschool-short-cycle', [
            'enrollment_id' => $this->enrollmentPrimary1->id,
            'payment_date'  => '2026-09-15',
            'method'        => 'cash',
            'cycle_mode'    => 'full_rate',
        ]);

        $shortCycleRes->assertStatus(422)
            ->assertJsonFragment(['message' => 'هذا المسار مخصص حصراً لأقسام الروضة والتمهيدي والتحضيري (PRE1, PRE2, PRE3).']);
    }

    /** 29. التحقق من رفض المبالغ اليدوية الصفرية أو السالبة */
    public function test_29_preschool_manual_amount_rejects_negative_or_zero(): void
    {
        // 1. مبلغ صفر
        $resZero = $this->actingAs($this->user)->postJson('/api/payments/collect', [
            'student_id'    => $this->enrollmentPre1->student_id,
            'enrollment_id' => $this->enrollmentPre1->id,
            'months'        => ['2026-09'],
            'payment_date'  => '2026-09-15',
            'method'        => 'cash',
            'manual_amount' => 0,
        ]);
        $resZero->assertStatus(422);

        // 2. مبلغ سالب
        $resNegative = $this->actingAs($this->user)->postJson('/api/payments/collect', [
            'student_id'    => $this->enrollmentPre1->student_id,
            'enrollment_id' => $this->enrollmentPre1->id,
            'months'        => ['2026-09'],
            'payment_date'  => '2026-09-15',
            'method'        => 'cash',
            'manual_amount' => -25,
        ]);
        $resNegative->assertStatus(422);
    }

    /** 30. المبلغ اليدوي الذي يتجاوز المعلوم الكامل يتطلب تأكيداً صريحاً */
    public function test_30_preschool_manual_amount_over_standard_rate_requires_confirmation(): void
    {
        // PRE1 المعلوم الكامل 100 د.ت والمقترح 50 د.ت — نحاول استخلاص 150 د.ت بدون تأكيد
        $resWithoutConfirm = $this->actingAs($this->user)->postJson('/api/payments/collect', [
            'student_id'    => $this->enrollmentPre1->student_id,
            'enrollment_id' => $this->enrollmentPre1->id,
            'months'        => ['2026-09'],
            'payment_date'  => '2026-09-15',
            'method'        => 'cash',
            'manual_amount' => 150.00,
        ]);

        $resWithoutConfirm->assertStatus(422)
            ->assertJsonPath('over_standard', true)
            ->assertJsonPath('entered_amount', 150)
            ->assertJsonPath('full_rate', 100)
            ->assertJsonPath('suggested_amount', 50);

        // إعادة المحاولة مع confirm_over_standard = true
        $resWithConfirm = $this->actingAs($this->user)->postJson('/api/payments/collect', [
            'student_id'            => $this->enrollmentPre1->student_id,
            'enrollment_id'         => $this->enrollmentPre1->id,
            'months'                => ['2026-09'],
            'payment_date'          => '2026-09-15',
            'method'                => 'cash',
            'manual_amount'         => 150.00,
            'confirm_over_standard' => true,
        ]);

        $resWithConfirm->assertStatus(201);

        $payment = Payment::where('enrollment_id', $this->enrollmentPre1->id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals(150.00, (float) $payment->amount);
        $this->assertEquals(150.0, (float) $payment->meta['preschool_manual_amounts']['2026-09']['entered_amount']);
        $this->assertEquals(50.0, (float) $payment->meta['preschool_manual_amounts']['2026-09']['suggested_amount']);
        $this->assertTrue($payment->meta['preschool_manual_amounts']['2026-09']['over_standard']);
    }

    /** 31. استخلاص سبتمبر وجوان معاً بمبالغ يدوية مختلفة (45 + 55 د.ت) */
    public function test_31_preschool_september_and_june_both_collected_with_different_amounts(): void
    {
        $res = $this->actingAs($this->user)->postJson('/api/payments/collect', [
            'student_id'     => $this->enrollmentPre1->student_id,
            'enrollment_id'  => $this->enrollmentPre1->id,
            'months'         => ['2026-09', '2027-06'],
            'payment_date'   => '2026-09-15',
            'method'         => 'cash',
            'manual_amounts' => [
                '2026-09' => 45.00,
                '2027-06' => 55.00,
            ],
        ]);

        $res->assertStatus(201);

        $payment = Payment::where('enrollment_id', $this->enrollmentPre1->id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals(100.00, (float) $payment->amount);

        // تم إنشاء رسمين منفصلين ومخصصين لكل شهر
        $fees = StudentFee::where('enrollment_id', $this->enrollmentPre1->id)->orderBy('id')->get();
        $this->assertCount(2, $fees);

        $septFee = $fees->firstWhere('amount_due', 45.00);
        $this->assertNotNull($septFee);
        $this->assertEquals('paid', $septFee->status);

        $juneFee = $fees->firstWhere('amount_due', 55.00);
        $this->assertNotNull($juneFee);
        $this->assertEquals('paid', $juneFee->status);

        // قيد الخزينة الموحد بمبلغ 100 د.ت
        $tx = CashTransaction::where('source_type', Payment::class)->where('source_id', $payment->id)->first();
        $this->assertNotNull($tx);
        $this->assertEquals(100.00, (float) $tx->amount);
    }

    /** 32. رفض المبالغ اليدوية لتلاميذ التعليم الأساسي (الابتدائي) */
    public function test_32_primary_student_rejects_manual_amount(): void
    {
        $res = $this->actingAs($this->user)->postJson('/api/payments/collect', [
            'student_id'    => $this->enrollmentPrimary1->student_id,
            'enrollment_id' => $this->enrollmentPrimary1->id,
            'months'        => ['2026-09'],
            'payment_date'  => '2026-09-15',
            'method'        => 'cash',
            'manual_amount' => 50.00,
            'items'         => [
                ['fee_type_id' => $this->tuitionFeeType->id, 'amount' => 50.00],
            ],
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['manual_amount']);
    }

    /** 33. منع تكرار استخلاص نفس الشهر لما قبل المدرسي عبر assertMonthsNotCollected */
    public function test_33_duplicate_collection_attempt_on_preschool_month_is_rejected(): void
    {
        // 1. الاستخلاص الأول ينجح
        $resFirst = $this->actingAs($this->user)->postJson('/api/payments/collect', [
            'student_id'    => $this->enrollmentPre1->student_id,
            'enrollment_id' => $this->enrollmentPre1->id,
            'months'        => ['2026-09'],
            'payment_date'  => '2026-09-15',
            'method'        => 'cash',
            'manual_amount' => 40.00,
        ]);
        $resFirst->assertStatus(201);

        // 2. محاولة استخلاص سبتمبر ثانية تُرفض بحارس الأشهر الموحد
        $resSecond = $this->actingAs($this->user)->postJson('/api/payments/collect', [
            'student_id'    => $this->enrollmentPre1->student_id,
            'enrollment_id' => $this->enrollmentPre1->id,
            'months'        => ['2026-09'],
            'payment_date'  => '2026-09-20',
            'method'        => 'cash',
            'manual_amount' => 40.00,
        ]);
        $resSecond->assertStatus(422);
        $this->assertStringContainsString('الشهر المطلوب (2026-09) مستخلص مسبقاً لهذا التلميذ', $resSecond->json('message'));
    }

    /** 34. استخلاص مختلط: سبتمبر بمبلغ يدوي حر (مثلاً 30 د.ت < 50) مع أكتوبر بالمعلوم العادي (100 د.ت) */
    public function test_34_mixed_collection_september_manual_and_october_standard_calculates_and_allocates_accurately(): void
    {
        // 1. فحص المعاينة لشهرين مختلطين مع مبلغ يدوي لسبتمبر
        $previewRes = $this->actingAs($this->user)->getJson('/api/payments/collect/preview?' . http_build_query([
            'enrollment_id'  => $this->enrollmentPre1->id,
            'months'         => ['2026-09', '2026-10'],
            'fee_type_id'    => $this->tuitionFeeType->id,
            'manual_amounts' => ['2026-09' => 30.00],
        ]));
        $previewRes->assertOk();
        $this->assertEquals(130.00, (float) $previewRes->json('remaining_amount'));

        // 2. تنفيذ الاستخلاص المشترك لسبتمبر (30 د.ت) وأكتوبر (100 د.ت)
        $res = $this->actingAs($this->user)->postJson('/api/payments/collect', [
            'student_id'     => $this->enrollmentPre1->student_id,
            'enrollment_id'  => $this->enrollmentPre1->id,
            'months'         => ['2026-09', '2026-10'],
            'payment_date'   => '2026-09-15',
            'method'         => 'cash',
            'manual_amounts' => ['2026-09' => 30.00],
            'items'          => [
                ['fee_type_id' => $this->tuitionFeeType->id, 'amount' => 130.00],
            ],
        ]);

        $res->assertStatus(201);
        $this->assertEquals(130.00, (float) $res->json('receipt.total'));

        // 3. التحقق من إنشاء رسمين منفصلين بالمبالغ المحددة
        $fees = StudentFee::where('enrollment_id', $this->enrollmentPre1->id)->orderBy('due_date')->get();
        $this->assertCount(2, $fees);

        $sepFee = $fees->first(fn ($f) => $f->due_date->format('Y-m-d') === '2026-09-01');
        $this->assertNotNull($sepFee);
        $this->assertEquals(30.00, (float) $sepFee->amount_due);
        $this->assertEquals('paid', $sepFee->status);

        $octFee = $fees->first(fn ($f) => $f->due_date->format('Y-m-d') === '2026-10-01');
        $this->assertNotNull($octFee);
        $this->assertEquals(100.00, (float) $octFee->amount_due);
        $this->assertEquals('paid', $octFee->status);

        // 4. التحقق من الدفعة والتخصيصات وقيد الخزينة
        $payment = Payment::where('enrollment_id', $this->enrollmentPre1->id)->firstOrFail();
        $this->assertEquals(130.00, (float) $payment->amount);
        $this->assertCount(2, $payment->paymentAllocations);

        $cashTx = CashTransaction::where('source_type', Payment::class)
            ->where('source_id', $payment->id)
            ->firstOrFail();
        $this->assertEquals(130.00, (float) $cashTx->amount);
        $this->assertEquals('monthly_fee', $cashTx->category);
        $this->assertEquals('in', $cashTx->direction);
    }
}