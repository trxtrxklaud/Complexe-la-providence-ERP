<?php

namespace Tests\Unit;

use App\Exceptions\MonthCollectionException;
use App\Models\AcademicYear;
use App\Models\Club;
use App\Models\ClubMonthlyFee;
use App\Models\ClubSubscription;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\Level;
use App\Models\ManualStudentDebt;
use App\Models\OpeningBalance;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\MonthCollectionCoreService;
use App\Services\PreschoolShortCycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class MonthCollectionCoreServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected AcademicYear $academicYear;
    protected FeeCategory $tuitionCategory;
    protected FeeType $tuitionFeeType;
    protected Level $levelPre1;
    protected Level $levelPre2;
    protected Level $levelPre3;
    protected Level $levelL1;
    protected FeePlan $planPre1;
    protected FeePlan $planPre2;
    protected FeePlan $planPre3;
    protected FeePlan $planL1;
    protected Enrollment $enrollmentPre1;
    protected Enrollment $enrollmentPre2;
    protected Enrollment $enrollmentPre3;
    protected Enrollment $enrollmentL1;
    protected CollectionService $collectionService;
    protected PreschoolShortCycleService $shortCycleService;
    protected MonthCollectionCoreService $coreService;

    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::create([
            'name'         => 'manage_payments',
            'display_name' => 'إدارة التحصيل والدفعات',
            'module'       => 'finance',
        ]);

        $role = Role::create([
            'name'         => 'cashier',
            'display_name' => 'قابض',
        ]);
        $role->permissions()->attach($permission->id);

        $this->user = User::create([
            'role_id'    => $role->id,
            'username'   => 'cashier_core_test',
            'first_name' => 'هند',
            'last_name'  => 'البوعزيزي',
            'email'      => 'cashier_core@school.test',
            'password'   => bcrypt('password'),
            'is_active'  => true,
        ]);

        $this->academicYear = AcademicYear::create([
            'name'       => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date'   => '2027-06-30',
            'is_active'  => true,
            'is_current' => true,
        ]);

        $this->tuitionCategory = FeeCategory::create([
            'name'         => 'معاليم التمدرس الشهري',
            'code'         => 'TUITION_MONTHLY',
            'is_recurring' => true,
        ]);

        $this->tuitionFeeType = FeeType::create([
            'name_ar'         => 'معلوم التمدرس الشهري',
            'name_fr'         => 'Frais de scolarite mensuelle',
            'code'            => 'TUITION',
            'price'           => 100.00,
            'is_recurring'    => true,
            'is_active'       => true,
            'fee_category_id' => $this->tuitionCategory->id,
            'ledger_category' => 'monthly_fee',
        ]);

        $this->levelPre1 = Level::create(['name' => 'روضة', 'code' => 'PRE1', 'order' => 1]);
        $this->levelPre2 = Level::create(['name' => 'تمهيدي', 'code' => 'PRE2', 'order' => 2]);
        $this->levelPre3 = Level::create(['name' => 'تحضيري', 'code' => 'PRE3', 'order' => 3]);
        $this->levelL1   = Level::create(['name' => 'سنة أولى', 'code' => 'L1', 'order' => 4]);

        $secPre1 = Section::create(['name' => 'فوج الروضة', 'code' => 'PRE1-A', 'level_id' => $this->levelPre1->id]);
        $secPre2 = Section::create(['name' => 'فوج التمهيدي', 'code' => 'PRE2-A', 'level_id' => $this->levelPre2->id]);
        $secPre3 = Section::create(['name' => 'فوج التحضيري', 'code' => 'PRE3-A', 'level_id' => $this->levelPre3->id]);
        $secL1   = Section::create(['name' => 'فوج الأولى أ', 'code' => 'L1-A', 'level_id' => $this->levelL1->id]);

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

        $this->planL1 = FeePlan::create([
            'academic_year_id' => $this->academicYear->id,
            'level_id'         => $this->levelL1->id,
            'fee_category_id'  => $this->tuitionCategory->id,
            'name'             => 'القسط الشهري — الابتدائي',
            'amount'           => 140.00,
            'frequency'        => 'monthly',
            'due_day'          => 1,
        ]);

        $student1 = Student::create([
            'student_code' => 'PRV-TEST-PRE1',
            'first_name'   => 'كريم',
            'last_name'    => 'المحمودي',
            'gender'       => 'boy',
            'birth_date'   => '2022-01-01',
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

        $studentPre2 = Student::create([
            'student_code' => 'PRV-TEST-PRE2',
            'first_name'   => 'سارة',
            'last_name'    => 'المحمودي',
            'gender'       => 'girl',
            'birth_date'   => '2021-08-01',
            'status'       => 'active',
        ]);
        $this->enrollmentPre2 = Enrollment::create([
            'student_id'       => $studentPre2->id,
            'academic_year_id' => $this->academicYear->id,
            'level_id'         => $this->levelPre2->id,
            'section_id'       => $secPre2->id,
            'enrollment_date'  => '2026-09-01',
            'status'           => 'active',
        ]);

        $student2 = Student::create([
            'student_code' => 'PRV-TEST-PRE3',
            'first_name'   => 'ريان',
            'last_name'    => 'المحمودي',
            'gender'       => 'boy',
            'birth_date'   => '2021-03-01',
            'status'       => 'active',
        ]);
        $this->enrollmentPre3 = Enrollment::create([
            'student_id'       => $student2->id,
            'academic_year_id' => $this->academicYear->id,
            'level_id'         => $this->levelPre3->id,
            'section_id'       => $secPre3->id,
            'enrollment_date'  => '2026-09-01',
            'status'           => 'active',
        ]);

        $studentL1 = Student::create([
            'student_code' => 'PRV-TEST-L1',
            'first_name'   => 'ياسين',
            'last_name'    => 'العياري',
            'gender'       => 'boy',
            'birth_date'   => '2020-05-01',
            'status'       => 'active',
        ]);
        $this->enrollmentL1 = Enrollment::create([
            'student_id'       => $studentL1->id,
            'academic_year_id' => $this->academicYear->id,
            'level_id'         => $this->levelL1->id,
            'section_id'       => $secL1->id,
            'enrollment_date'  => '2026-09-01',
            'status'           => 'active',
        ]);

        $this->collectionService = app(CollectionService::class);
        $this->shortCycleService = app(PreschoolShortCycleService::class);
        $this->coreService = app(MonthCollectionCoreService::class);
    }

    /**
     * 1. قبول شهري سبتمبر وجوان في المسار القياسي بمبالغ يدوية لكافة مستويات PRE1, PRE2, PRE3.
     */
    public function test_1_standard_collection_accepts_september_and_june_for_pre1_pre2_pre3(): void
    {
        $enrollments = [
            'PRE1' => ['enrollment' => $this->enrollmentPre1, 'amount' => 45.0],
            'PRE2' => ['enrollment' => $this->enrollmentPre2, 'amount' => 50.0],
            'PRE3' => ['enrollment' => $this->enrollmentPre3, 'amount' => 55.0],
        ];

        foreach ($enrollments as $code => $data) {
            $enr = $data['enrollment'];
            $amt = $data['amount'];
            foreach (['2026-09', '2027-06'] as $m) {
                // فحص preview ينجح ويعيد بيانات سليمة
                $prev = $this->collectionService->preview($enr->id, [$m], $this->tuitionFeeType->id, 'full', [$m => $amt]);
                $this->assertIsArray($prev);

                // فحص collect ينجح
                $receipt = $this->collectionService->collect([
                    'student_id'      => $enr->student_id,
                    'enrollment_id'   => $enr->id,
                    'months'          => [$m],
                    'collection_mode' => 'full',
                    'manual_amount'   => $amt,
                    'payment_date'    => '2026-09-10',
                    'method'          => 'cash',
                    'items'           => [
                        ['fee_type_id' => $this->tuitionFeeType->id, 'amount' => $amt],
                    ],
                    'idempotency_key' => "IDEM_ACCEPT_{$code}_{$m}",
                ], $this->user->id);

                $this->assertNotNull($receipt['payment_id']);
                $this->assertEquals($amt, $receipt['total']);
            }
        }
    }

    /**
     * 2. استخلاص نصف سبتمبر عبر المسار المبسط ينجح لـ PRE1, PRE2, PRE3، ثم يُرفض أي استخلاص لاحق لنفس الشهر.
     */
    public function test_2_preschool_short_cycle_half_rate_then_subsequent_attempts_rejected_across_all_three_levels(): void
    {
        $cases = [
            'PRE1' => ['enrollment' => $this->enrollmentPre1, 'half' => 50.0],
            'PRE2' => ['enrollment' => $this->enrollmentPre2, 'half' => 50.0],
            'PRE3' => ['enrollment' => $this->enrollmentPre3, 'half' => 60.0],
        ];

        foreach ($cases as $code => $data) {
            $enr = $data['enrollment'];
            $half = $data['half'];

            // الاستخلاص الأولي عبر المسار المبسط
            $res = $this->shortCycleService->collect([
                'enrollment_id'   => $enr->id,
                'cycle_mode'      => 'half_rate',
                'half_rate_month' => '2026-09',
                'payment_date'    => '2026-09-10',
                'method'          => 'cash',
                'idempotency_key' => "IDEM_HALF_{$code}",
            ], $this->user->id);

            $this->assertEquals($half, $res['amount']);

            // المحاولة الثانية عبر المسار المبسط -> رفض
            try {
                $this->shortCycleService->collect([
                    'enrollment_id'   => $enr->id,
                    'cycle_mode'      => 'half_rate',
                    'half_rate_month' => '2026-09',
                    'payment_date'    => '2026-09-12',
                    'method'          => 'cash',
                    'idempotency_key' => "IDEM_HALF_RETRY_{$code}",
                ], $this->user->id);
                $this->fail("Expected InvalidArgumentException for repeated short cycle on {$code}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('مستخلص مسبقاً', $e->getMessage());
            }

            // المحاولة عبر المسار القياسي -> رفض بالرسالة المعتمدة
            try {
                $this->collectionService->collect([
                    'student_id'      => $enr->student_id,
                    'enrollment_id'   => $enr->id,
                    'months'          => ['2026-09'],
                    'collection_mode' => 'full',
                    'payment_date'    => '2026-09-12',
                    'method'          => 'cash',
                    'items'           => [
                        ['fee_type_id' => $this->tuitionFeeType->id, 'amount' => $half * 2],
                    ],
                    'idempotency_key' => "IDEM_STD_RETRY_{$code}",
                ], $this->user->id);
                $this->fail("Expected InvalidArgumentException for standard collection on {$code}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('مستخلص مسبقاً', $e->getMessage());
            }
        }
    }

    /**
     * 3. استخلاص المعلوم الكامل (سبتمبر + جوان) ينجح لـ PRE1, PRE2, PRE3، وأي محاولة لاحقة لأي شهر تُرفض.
     */
    public function test_3_preschool_short_cycle_full_rate_then_subsequent_attempts_rejected_across_all_three_levels(): void
    {
        $cases = [
            'PRE1' => ['enrollment' => $this->enrollmentPre1, 'full' => 100.0],
            'PRE2' => ['enrollment' => $this->enrollmentPre2, 'full' => 100.0],
            'PRE3' => ['enrollment' => $this->enrollmentPre3, 'full' => 120.0],
        ];

        foreach ($cases as $code => $data) {
            $enr = $data['enrollment'];
            $full = $data['full'];

            // استخلاص كامل
            $res = $this->shortCycleService->collect([
                'enrollment_id'   => $enr->id,
                'cycle_mode'      => 'full_rate',
                'payment_date'    => '2026-09-05',
                'method'          => 'cash',
                'idempotency_key' => "IDEM_FULL_{$code}",
            ], $this->user->id);

            $this->assertEquals($full, $res['amount']);

            // محاولة لاحقة لنصف سبتمبر عبر المسار المبسط -> مرفوضة
            try {
                $this->shortCycleService->collect([
                    'enrollment_id'   => $enr->id,
                    'cycle_mode'      => 'half_rate',
                    'half_rate_month' => '2026-09',
                    'payment_date'    => '2026-09-10',
                    'method'          => 'cash',
                    'idempotency_key' => "IDEM_FULL_RETRY_SEP_{$code}",
                ], $this->user->id);
                $this->fail("Expected rejection of half_rate September after full_rate on {$code}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('مستخلص مسبقاً', $e->getMessage());
            }

            // محاولة لاحقة لنصف جوان عبر المسار المبسط -> مرفوضة
            try {
                $this->shortCycleService->collect([
                    'enrollment_id'   => $enr->id,
                    'cycle_mode'      => 'half_rate',
                    'half_rate_month' => '2027-06',
                    'payment_date'    => '2027-06-10',
                    'method'          => 'cash',
                    'idempotency_key' => "IDEM_FULL_RETRY_JUN_{$code}",
                ], $this->user->id);
                $this->fail("Expected rejection of half_rate June after full_rate on {$code}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('مستخلص مسبقاً', $e->getMessage());
            }
        }
    }

    /**
     * 4. المستوى الابتدائي L1 يقبل سبتمبر في المسار القياسي، ويُرفض حصراً في مسار الدورة المبسطة.
     */
    public function test_4_primary_level_can_collect_september_via_standard_and_is_rejected_by_short_cycle(): void
    {
        // 1. المسار القياسي ينجح للمستوى الابتدائي في سبتمبر
        $receipt = $this->collectionService->collect([
            'student_id'      => $this->enrollmentL1->student_id,
            'enrollment_id'   => $this->enrollmentL1->id,
            'months'          => ['2026-09'],
            'collection_mode' => 'full',
            'payment_date'    => '2026-09-05',
            'method'          => 'cash',
            'items'           => [
                ['fee_type_id' => $this->tuitionFeeType->id, 'amount' => 140.00],
            ],
            'idempotency_key' => 'IDEM_L1_STD_SEP',
        ], $this->user->id);

        $this->assertNotNull($receipt['payment_id']);
        $this->assertEquals(140.00, $receipt['total']);
        $this->assertContains('2026-09', $this->collectionService->getPaidMonths($this->enrollmentL1->id));

        // 2. محاولة استخلاص المستوى الابتدائي عبر المسار المبسط تُرفض بصرامة
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('هذا المسار مخصص حصراً لأقسام الروضة والتمهيدي والتحضيري (PRE1, PRE2, PRE3).');

        $this->shortCycleService->collect([
            'enrollment_id'   => $this->enrollmentL1->id,
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2026-09',
            'payment_date'    => '2026-09-10',
            'method'          => 'cash',
            'idempotency_key' => 'IDEM_L1_SHORT_REJECT',
        ], $this->user->id);
    }

    /**
     * 5. Payment ملغاة -> يسمح بتحصيل الشهر لاحقاً.
     */
    public function test_5_cancelled_payment_allows_collecting_month_again(): void
    {
        $res = $this->shortCycleService->collect([
            'enrollment_id'   => $this->enrollmentPre1->id,
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2026-09',
            'payment_date'    => '2026-09-10',
            'method'          => 'cash',
            'idempotency_key' => 'IDEM_5_ORIGINAL',
        ], $this->user->id);

        $payment = Payment::findOrFail($res['payment_id']);

        // إلغاء الدفعة
        $payment->update([
            'cancelled_at'        => now(),
            'cancelled_by'        => $this->user->id,
            'cancellation_reason' => 'إلغاء تجريبي للاختبار',
        ]);

        // إعادة حالة الرسم إلى pending
        StudentFee::where('enrollment_id', $this->enrollmentPre1->id)
            ->whereDate('due_date', '2026-09-01')
            ->update(['status' => 'pending']);

        // يجب أن ينجح التحصيل الجديد بعد الإلغاء بسلاسة
        $res2 = $this->shortCycleService->collect([
            'enrollment_id'   => $this->enrollmentPre1->id,
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2026-09',
            'payment_date'    => '2026-09-15',
            'method'          => 'cash',
            'idempotency_key' => 'IDEM_5_NEW',
        ], $this->user->id);

        $this->assertNotNull($res2['payment_id']);
        $this->assertNotEquals($res['payment_id'], $res2['payment_id']);
    }

    /**
     * 6. Payment نادي لا يمنع شهر دراسي.
     */
    public function test_6_club_payment_does_not_block_study_month(): void
    {
        $clubCategory = FeeCategory::create(['code' => 'CLUB', 'name' => 'نوادي', 'is_recurring' => true]);
        $club = Club::create([
            'name'            => 'نادي الموسيقى',
            'fee_category_id' => $clubCategory->id,
            'monthly_fee'     => 35.00,
            'is_active'       => true,
        ]);

        $sub = ClubSubscription::create([
            'academic_year_id' => $this->academicYear->id,
            'enrollment_id'    => $this->enrollmentPre1->id,
            'student_id'       => $this->enrollmentPre1->student_id,
            'club_id'          => $club->id,
            'start_date'       => '2026-09-01',
            'status'           => 'active',
        ]);

        $clubFee = ClubMonthlyFee::create([
            'club_subscription_id' => $sub->id,
            'academic_year_id'     => $this->academicYear->id,
            'enrollment_id'        => $this->enrollmentPre1->id,
            'student_id'           => $this->enrollmentPre1->student_id,
            'club_id'              => $club->id,
            'month'                => '2026-09',
            'amount_due'           => 35.00,
            'amount_paid'          => 35.00,
            'status'               => 'paid',
        ]);

        $clubStudentFee = StudentFee::create([
            'enrollment_id'       => $this->enrollmentPre1->id,
            'club_monthly_fee_id' => $clubFee->id,
            'description'         => 'معلوم نادي الموسيقى — سبتمبر 2026',
            'amount_due'          => 35.00,
            'direct_paid_amount'  => 35.00,
            'due_date'            => '2026-09-01',
            'status'              => 'paid',
        ]);

        $clubPayment = Payment::create([
            'student_id'    => $this->enrollmentPre1->student_id,
            'enrollment_id' => $this->enrollmentPre1->id,
            'months'        => ['2026-09'],
            'amount'        => 35.00,
            'payment_date'  => '2026-09-01',
            'method'        => 'cash',
        ]);

        PaymentAllocation::create([
            'payment_id'       => $clubPayment->id,
            'student_fee_id'   => $clubStudentFee->id,
            'amount_allocated' => 35.00,
        ]);

        // استخلاص تمدرس سبتمبر يجب أن ينجح دون أن يُعرقله معلوم النادي
        $res = $this->shortCycleService->collect([
            'enrollment_id'   => $this->enrollmentPre1->id,
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2026-09',
            'payment_date'    => '2026-09-10',
            'method'          => 'cash',
            'idempotency_key' => 'IDEM_6_PRESCHOOL',
        ], $this->user->id);

        $this->assertNotNull($res['payment_id']);
        $this->assertEquals(50.00, $res['amount']);
    }

    /**
     * 7. prior_year_debt لا يمنع شهر حالي.
     */
    public function test_7_prior_year_debt_does_not_block_current_month(): void
    {
        $oldFee = StudentFee::create([
            'enrollment_id'      => $this->enrollmentPre1->id,
            'description'        => 'دين متخلد عن سنة سابقة',
            'amount_due'         => 80.00,
            'direct_paid_amount' => 80.00,
            'due_date'           => '2026-09-01', // يحمل نفس التاريخ لكنه دين قديم
            'status'             => 'paid',
        ]);

        $debt = ManualStudentDebt::create([
            'student_id'            => $this->enrollmentPre1->student_id,
            'source_student_fee_id' => $oldFee->id,
            'original_amount'       => 80.00,
            'original_year_label'   => '2025-2026',
            'description'           => 'دين متخلد عن سنة سابقة',
            'status'                => ManualStudentDebt::STATUS_PAID,
            'academic_year_id'      => $this->academicYear->id,
            'note'                  => 'دين قديم',
            'created_by'            => $this->user->id,
        ]);

        $oldPayment = Payment::create([
            'student_id'    => $this->enrollmentPre1->student_id,
            'enrollment_id' => $this->enrollmentPre1->id,
            'months'        => [],
            'amount'        => 80.00,
            'payment_date'  => '2026-09-02',
            'method'        => 'cash',
        ]);

        PaymentAllocation::create([
            'payment_id'             => $oldPayment->id,
            'student_fee_id'         => $oldFee->id,
            'manual_student_debt_id' => $debt->id,
            'amount_allocated'       => 80.00,
        ]);

        // تحصيل سبتمبر الحالي يجب أن ينجح
        $res = $this->shortCycleService->collect([
            'enrollment_id'   => $this->enrollmentPre1->id,
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2026-09',
            'payment_date'    => '2026-09-10',
            'method'          => 'cash',
            'idempotency_key' => 'IDEM_7_PRESCHOOL',
        ], $this->user->id);

        $this->assertNotNull($res['payment_id']);
    }

    /**
     * 8. تناقض months/allocation -> UNRESOLVED_MONTH_OWNERSHIP.
     */
    public function test_8_months_allocation_mismatch_throws_unresolved_month_ownership(): void
    {
        // إنشاء دفعة شاذة: توثق 2026-09 في months لكن لا تملك أي تخصيص مالي
        $corruptPayment = Payment::create([
            'student_id'    => $this->enrollmentPre1->student_id,
            'enrollment_id' => $this->enrollmentPre1->id,
            'months'        => ['2026-09'],
            'amount'        => 100.00,
            'payment_date'  => '2026-09-01',
            'method'        => 'cash',
        ]);

        try {
            DB::transaction(function () {
                $locked = $this->coreService->lockEnrollment($this->enrollmentPre1->id);
                $this->coreService->assertMonthsNotCollected($locked, ['2026-09']);
            });
            $this->fail('Expected MonthCollectionException was not thrown');
        } catch (MonthCollectionException $e) {
            $this->assertEquals('UNRESOLVED_MONTH_OWNERSHIP', $e->getErrorCode());
            $ctx = $e->getContext();
            $this->assertEquals($this->enrollmentPre1->id, $ctx['enrollment_id']);
            $this->assertEquals('2026-09', $ctx['month_key']);
            $this->assertContains($corruptPayment->id, $ctx['conflict_ids']['payment_ids']);
        }
    }

    /**
     * 9. نفس idempotency key -> نفس Payment.
     */
    public function test_9_same_idempotency_key_returns_same_payment(): void
    {
        $key = 'IDEM_TEST_KEY_RETRY_123';

        $res1 = $this->shortCycleService->collect([
            'enrollment_id'   => $this->enrollmentPre1->id,
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2026-09',
            'payment_date'    => '2026-09-10',
            'method'          => 'cash',
            'idempotency_key' => $key,
        ], $this->user->id);

        $res2 = $this->shortCycleService->collect([
            'enrollment_id'   => $this->enrollmentPre1->id,
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2026-09',
            'payment_date'    => '2026-09-10',
            'method'          => 'cash',
            'idempotency_key' => $key,
        ], $this->user->id);

        $this->assertEquals($res1['payment_id'], $res2['payment_id']);
        $this->assertEquals($res1['amount'], $res2['amount']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('cash_transactions', 1);
    }

    /**
     * 10. مفتاحان مختلفان لنفس الشهر -> رفض الثاني.
     */
    public function test_10_different_idempotency_keys_for_same_month_rejects_second(): void
    {
        $res1 = $this->shortCycleService->collect([
            'enrollment_id'   => $this->enrollmentPre1->id,
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2026-09',
            'payment_date'    => '2026-09-10',
            'method'          => 'cash',
            'idempotency_key' => 'IDEM_KEY_ALPHA',
        ], $this->user->id);

        $this->assertNotNull($res1['payment_id']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('مستخلص مسبقاً');

        $this->shortCycleService->collect([
            'enrollment_id'   => $this->enrollmentPre1->id,
            'cycle_mode'      => 'half_rate',
            'half_rate_month' => '2026-09',
            'payment_date'    => '2026-09-10',
            'method'          => 'cash',
            'idempotency_key' => 'IDEM_KEY_BETA',
        ], $this->user->id);
    }
}
