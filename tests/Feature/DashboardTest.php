<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Level;
use App\Models\ManualStudentDebt;
use App\Models\Permission;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\OpeningBalanceService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_does_not_receive_financial_data(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('cashier', ['manage_payments', 'manage_students']));
        $this->makeAcademicYear();

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        // بيانات التلاميذ والمتخلَّد تبقى متاحة للقابض
        $this->assertArrayHasKey('total_students', $data);
        $this->assertArrayHasKey('outstanding_balance', $data);

        // الجرد النقدي محجوب تماماً على من لا يملك manage_treasury/view_reports
        $this->assertArrayNotHasKey('cash', $data);
        $this->assertArrayNotHasKey('treasury_balance', $data);
        $this->assertArrayNotHasKey('financial_summary', $data);
        $this->assertArrayNotHasKey('prior_debt_summary', $data);
    }

    public function test_report_viewer_receives_financial_data(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('report_viewer', ['view_reports']));
        $this->makeAcademicYear();

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertArrayHasKey('cash', $data);
        $this->assertArrayHasKey('treasury_balance', $data);
        $this->assertArrayHasKey('financial_summary', $data);
    }

    public function test_treasury_manager_receives_financial_data(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('treasurer', ['manage_treasury']));
        $this->makeAcademicYear();

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        // manage_treasury وحدها تكفي — إثبات دلالة "أو"
        $this->assertArrayHasKey('cash', $data);
        $this->assertArrayHasKey('treasury_balance', $data);
        $this->assertArrayHasKey('financial_summary', $data);
        $this->assertArrayHasKey('prior_debt_summary', $data);
    }

    public function test_prior_debt_summary_figures_match_ledger_and_manual_records(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('treasurer', ['manage_treasury']));
        $year = $this->makeAcademicYear();

        // دَين تلميذ قديم: 10000 حُصّل منها 3000 (سطر في الدفتر النقدي).
        $student = Student::create([
            'student_code' => 'STU-PD-'.uniqid(),
            'first_name' => 'أحمد',
            'last_name' => 'بن صالح',
            'gender' => 'male',
            'status' => 'active',
        ]);
        ManualStudentDebt::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'tuition',
            'description' => 'متخلّدات سابقة',
            'original_amount' => 10000,
            'status' => ManualStudentDebt::STATUS_PENDING,
        ]);
        CashTransaction::create([
            'source_type' => 'payment',
            'source_id' => 99001,
            'category' => CashTransaction::CATEGORY_PRIOR_YEAR_DEBT,
            'direction' => CashTransaction::DIRECTION_IN,
            'amount' => 3000,
            'transaction_date' => now()->toDateString(),
            'academic_year_id' => $year->id,
        ]);

        // تحصيل دين إطار سابق كـ legacy CashTransaction: يدخل في المحصّل الكلّي
        CashTransaction::create([
            'source_type' => 'App\\Models\\EmployeeLiability',
            'source_id' => 999,
            'category' => CashTransaction::CATEGORY_OLD_LIABILITY_COLLECTION,
            'direction' => CashTransaction::DIRECTION_IN,
            'amount' => 200,
            'transaction_date' => now()->toDateString(),
            'academic_year_id' => $year->id,
        ]);

        $summary = $this->getJson('/api/dashboard')->assertOk()->json('data.prior_debt_summary');

        // المحصّل من الدفتر النقدي (بندا المتخلّدات وخلاص المستحقّات القديمة معاً).
        $this->assertEquals(3200, (float) $summary['total_collected']);
        // المتبقّي = 10000 (تلميذ، لا توزيعات هنا).
        $this->assertEquals(10000, (float) $summary['total_remaining']);

        $this->assertCount(1, $summary['student_details']);
        $this->assertSame('أحمد بن صالح', $summary['student_details'][0]['student_name']);
        $this->assertEquals(10000, (float) $summary['student_details'][0]['original_amount']);
        $this->assertEquals(0, (float) $summary['student_details'][0]['paid_amount']);
        $this->assertEquals(10000, (float) $summary['student_details'][0]['outstanding_amount']);

        $this->assertEmpty($summary['employee_details']);
    }

    public function test_admin_super_role_receives_financial_data(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('admin', []));
        $this->makeAcademicYear();

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        // الدور الخارق يتجاوز الفحص التفصيلي كما في CheckPermission
        $this->assertArrayHasKey('cash', $data);
        $this->assertArrayHasKey('treasury_balance', $data);
        $this->assertArrayHasKey('financial_summary', $data);
    }

    public function test_dashboard_returns_reconciled_gender_counts_excluding_duplicates_and_handling_unspecified_gender(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('admin', ['manage_students']));
        $year = $this->makeAcademicYear();

        $level = Level::firstOrCreate(['id' => 1], ['name' => 'السنة الأولى', 'code' => 'L1']);
        $sec1 = Section::firstOrCreate(['id' => 881], ['level_id' => $level->id, 'name' => 'قسم 1', 'code' => 'S881']);
        $sec2 = Section::firstOrCreate(['id' => 882], ['level_id' => $level->id, 'name' => 'قسم 2', 'code' => 'S882']);

        // Student 1: Male
        $maleStudent = Student::create([
            'student_code' => 'ST_MALE_1',
            'first_name' => 'أحمد',
            'last_name' => 'علي',
            'gender' => 'male',
        ]);
        Enrollment::create([
            'student_id' => $maleStudent->id,
            'academic_year_id' => $year->id,
            'level_id' => $level->id,
            'section_id' => $sec1->id,
            'status' => 'active',
            'enrollment_date' => now()->toDateString(),
        ]);

        // Student 2: Female with duplicate enrollment row in same active year
        $femaleStudent = Student::create([
            'student_code' => 'ST_FEMALE_1',
            'first_name' => 'مريم',
            'last_name' => 'بن طالب',
            'gender' => 'female',
        ]);
        Enrollment::create([
            'student_id' => $femaleStudent->id,
            'academic_year_id' => $year->id,
            'level_id' => $level->id,
            'section_id' => $sec1->id,
            'status' => 'active',
            'enrollment_date' => now()->toDateString(),
        ]);
        Enrollment::create([
            'student_id' => $femaleStudent->id,
            'academic_year_id' => $year->id,
            'level_id' => $level->id,
            'section_id' => $sec2->id,
            'status' => 'active',
            'enrollment_date' => now()->toDateString(),
        ]);

        // Student 3: Null/Unspecified gender with generic name
        $unspecifiedStudent = Student::create([
            'student_code' => 'ST_UNK_1',
            'first_name' => 'غير_معروف_123',
            'last_name' => 'مستورد',
            'gender' => null,
        ]);
        Enrollment::create([
            'student_id' => $unspecifiedStudent->id,
            'academic_year_id' => $year->id,
            'level_id' => $level->id,
            'section_id' => $sec1->id,
            'status' => 'active',
            'enrollment_date' => now()->toDateString(),
        ]);

        // Student 4: Null/Unspecified gender with male Arabic first name 'محمد'
        $unspecifiedArabicName = Student::create([
            'student_code' => 'ST_UNK_2',
            'first_name' => 'محمد',
            'last_name' => 'التونسي',
            'gender' => null,
        ]);
        Enrollment::create([
            'student_id' => $unspecifiedArabicName->id,
            'academic_year_id' => $year->id,
            'level_id' => $level->id,
            'section_id' => $sec1->id,
            'status' => 'active',
            'enrollment_date' => now()->toDateString(),
        ]);

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertEquals(4, $data['total_students']);
        $this->assertEquals(4, $data['total_active_students']);
        $this->assertEquals(2, $data['male_students_count']);
        $this->assertEquals(2, $data['total_males']);
        $this->assertEquals(1, $data['female_students_count']);
        $this->assertEquals(1, $data['total_females']);
        $this->assertEquals(1, $data['unknown_gender_count']);
        $this->assertEquals(1, $data['total_unspecified_gender']);

        // Reconciliation Assertion
        $this->assertEquals(
            $data['total_active_students'],
            $data['male_students_count'] + $data['female_students_count'] + $data['unknown_gender_count']
        );
    }

    /** إلغاء استخلاص = مسح كلي: الرسوم المؤقتة التي أنشأها تُحذف فلا يبقى شيء في المتخلّد. */
    public function test_dashboard_current_year_outstanding_erased_when_collection_payment_cancelled(): void
    {
        // admin دور خارق يتجاوز فحص الصلاحيات (تسجيل واستقبال وإلغاء ولوحة مالية).
        $user = $this->makeUserWithPermissions('admin', []);
        Sanctum::actingAs($user);

        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);

        $fee = StudentFee::create([
            'enrollment_id' => $enrollment->id,
            'fee_plan_id' => null,
            'description' => 'قسط شهري',
            'amount_due' => 300,
            'due_date' => '2025-09-05',
            'status' => 'pending',
        ]);

        // قبل السداد: كل المتخلَّد (300) على العام.
        $this->assertEquals(300, $this->dashboardPendingAmount());

        // سداد جزئي 200 → يتبقّى 100.
        $payment = app(PaymentService::class)->recordPayment([
            'student_id' => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'amount' => 200,
            'payment_date' => '2025-09-10',
            'method' => 'cash',
            'allocations' => [['student_fee_id' => $fee->id, 'amount' => 200]],
        ], $user->id);

        $this->assertEquals(100, $this->dashboardPendingAmount());

        // إلغاء الدفعة: بصمة العملية تُمحى كلياً — الرسم المؤقت يُحذف
        // فلا يظهر المبلغ في المتخلّد إطلاقاً.
        $this->postJson("/api/payments/{$payment->id}/cancel", ['reason' => 'تدقيق داخلي'])
            ->assertOk();

        $this->assertEquals(0, $this->dashboardPendingAmount());
        $this->assertDatabaseMissing('student_fees', ['id' => $fee->id]);
    }

    /** التوزيعات المنتمية لدفعات ملغاة لا تُخصم من متخلّد السنوات السابقة. */
    public function test_dashboard_prior_year_outstanding_excludes_cancelled_allocations(): void
    {
        $user = $this->makeUserWithPermissions('admin', []);
        Sanctum::actingAs($user);

        $old = AcademicYear::create([
            'name' => '2025-2026',
            'start_date' => '2025-09-01',
            'end_date' => '2026-06-30',
            'is_active' => false,
        ]);

        $new = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);

        $level = Level::firstOrCreate(
            ['id' => 90001],
            ['name' => 'السنة الثانية', 'code' => 'L90001', 'order' => 2]
        );
        $section = Section::firstOrCreate(
            ['id' => 90001],
            ['level_id' => $level->id, 'name' => 'ب', 'code' => 'S90001']
        );

        $student = Student::create([
            'student_code' => 'ST-PRIOR-YEAR',
            'first_name' => 'ليلى',
            'last_name' => 'بن عامر',
            'gender' => 'female',
            'status' => 'active',
        ]);

        $oldEnrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $old->id,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => '2025-09-01',
            'status' => 'active',
        ]);

        $oldFee = StudentFee::create([
            'enrollment_id' => $oldEnrollment->id,
            'fee_plan_id' => null,
            'description' => 'القسط الشهري — سبتمبر 2025',
            'amount_due' => 200,
            'due_date' => '2025-09-05',
            'status' => 'pending',
        ]);

        // إقفال السنة القديمة يرفع الرصيد الافتتاحي 200 إلى السنة النشطة.
        app(OpeningBalanceService::class)->closeYear($old, $new, $user->id);
        $this->assertEquals(200, $this->dashboardPriorYearOutstanding());

        // قبض الدَّين القديم بالكامل → متخلَّد السنوات السابقة = 0.
        $payment = app(PaymentService::class)->recordPayment([
            'student_id' => $student->id,
            'enrollment_id' => $oldEnrollment->id,
            'amount' => 200,
            'payment_date' => '2026-09-15',
            'method' => 'cash',
            'allocations' => [['student_fee_id' => $oldFee->id, 'amount' => 200]],
        ], $user->id);

        $this->assertEquals(0, $this->dashboardPriorYearOutstanding());

        // إلغاء الدفعة يعيد المتخلَّد القديم 200.
        $this->postJson("/api/payments/{$payment->id}/cancel", ['reason' => 'تدقيق داخلي'])
            ->assertOk();

        $this->assertEquals(200, $this->dashboardPriorYearOutstanding());
    }

    /**
     * الحالة الأولى: دخل سنة (1000) + تحصيل دين قديم (300) + مصاريف (400) + خلاص مستحق قديم (100).
     */
    public function test_cash_figures_calculation_with_current_income_old_debt_expenses_and_old_liability_payments(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('admin', ['manage_treasury']));
        $year = $this->makeAcademicYear();

        // 1. دخل السنة الحالية = 1000
        CashTransaction::create([
            'source_type' => 'payment',
            'source_id' => 101,
            'category' => CashTransaction::CATEGORY_MONTHLY_FEE,
            'direction' => CashTransaction::DIRECTION_IN,
            'amount' => 1000,
            'transaction_date' => now()->toDateString(),
            'academic_year_id' => $year->id,
        ]);

        // 2. تحصيل دين قديم = 300
        CashTransaction::create([
            'source_type' => 'payment',
            'source_id' => 102,
            'category' => CashTransaction::CATEGORY_PRIOR_YEAR_DEBT,
            'direction' => CashTransaction::DIRECTION_IN,
            'amount' => 300,
            'transaction_date' => now()->toDateString(),
            'academic_year_id' => $year->id,
        ]);

        // 3. مصاريف السنة الحالية = 400
        CashTransaction::create([
            'source_type' => 'expense',
            'source_id' => 103,
            'category' => CashTransaction::CATEGORY_EXPENSE,
            'direction' => CashTransaction::DIRECTION_OUT,
            'amount' => 400,
            'transaction_date' => now()->toDateString(),
            'academic_year_id' => $year->id,
        ]);

        // 4. خلاص مستحق قديم = 100
        CashTransaction::create([
            'source_type' => 'App\\Models\\EmployeeLiability',
            'source_id' => 104,
            'category' => CashTransaction::CATEGORY_OLD_LIABILITY_PAYMENT,
            'direction' => CashTransaction::DIRECTION_OUT,
            'amount' => 100,
            'transaction_date' => now()->toDateString(),
            'academic_year_id' => $year->id,
        ]);

        $cash = $this->getJson('/api/dashboard')->assertOk()->json('data.cash.all_time');

        $this->assertEquals(1000, (float) $cash['current_year_income']);
        $this->assertEquals(300, (float) $cash['old_debt_collections']);
        $this->assertEquals(1300, (float) $cash['cash_in']);
        $this->assertEquals(400, (float) $cash['expenses']);
        $this->assertEquals(100, (float) $cash['old_liability_payments']);
        $this->assertEquals(600, (float) $cash['net_income']);
        $this->assertEquals(800, (float) $cash['balance']);
    }

    /**
     * الحالة الثانية: حركة old_liability_payment بقيمة 100 تخرج من الصندوق ولا تُعد تدفقاً داخلاً.
     */
    public function test_old_liability_payment_is_cash_out_not_cash_in(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('admin', ['manage_treasury']));
        $year = $this->makeAcademicYear();

        CashTransaction::create([
            'source_type' => 'App\\Models\\EmployeeLiability',
            'source_id' => 201,
            'category' => CashTransaction::CATEGORY_OLD_LIABILITY_PAYMENT,
            'direction' => CashTransaction::DIRECTION_OUT,
            'amount' => 100,
            'transaction_date' => now()->toDateString(),
            'academic_year_id' => $year->id,
        ]);

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');
        $cash = $data['cash']['all_time'];
        $prior = $data['prior_debt_summary'];

        $this->assertEquals(0, (float) $cash['cash_in']);
        $this->assertEquals(0, (float) $cash['old_debt_collections']);
        $this->assertEquals(0, (float) $prior['total_collected']);
        $this->assertEquals(100, (float) $cash['old_liability_payments']);
        $this->assertEquals(-100, (float) $cash['balance']);
        $this->assertEquals(0, (float) $cash['net_income']);
    }

    /**
     * الحالة الثالثة: حركة old_liability_collection بقيمة 300 تدخل الصندوق وتزيد الرصيد دون أن تغير net_income.
     */
    public function test_old_liability_collection_is_cash_in_not_net_income(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('admin', ['manage_treasury']));
        $year = $this->makeAcademicYear();

        CashTransaction::create([
            'source_type' => 'App\\Models\\EmployeeLiability',
            'source_id' => 301,
            'category' => CashTransaction::CATEGORY_OLD_LIABILITY_COLLECTION,
            'direction' => CashTransaction::DIRECTION_IN,
            'amount' => 300,
            'transaction_date' => now()->toDateString(),
            'academic_year_id' => $year->id,
        ]);

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');
        $cash = $data['cash']['all_time'];
        $prior = $data['prior_debt_summary'];

        $this->assertEquals(300, (float) $cash['cash_in']);
        $this->assertEquals(300, (float) $cash['old_debt_collections']);
        $this->assertEquals(300, (float) $prior['total_collected']);
        $this->assertEquals(0, (float) $cash['net_income']);
        $this->assertEquals(300, (float) $cash['balance']);
    }

    private function dashboardPendingAmount(): int
    {
        return (int) $this->getJson('/api/dashboard')->assertOk()->json('data.financial_summary.pending_amount');
    }

    private function dashboardPriorYearOutstanding(): int
    {
        return (int) $this->getJson('/api/dashboard')->assertOk()->json('data.financial_summary.prior_year_outstanding');
    }

    /**
     * @param  array<int,string>  $permissions
     */
    private function makeUserWithPermissions(string $roleName, array $permissions): User
    {
        $user = $this->makeUser($roleName);
        $user->update(['is_active' => true]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['display_name' => $name, 'group' => 'Test']
            );
            $user->role->permissions()->syncWithoutDetaching($permission->id);
        }

        return $user;
    }

    /** اختبارات نسبة الترسيم وحساب المسددين وغير المسددين */
    public function test_registration_rate_with_full_partial_and_cancelled_payments(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('cashier', ['manage_payments', 'manage_students']));
        $year = $this->makeAcademicYear();

        // 1. نوع رسم ترسيم رسمي
        $regFeeType = \App\Models\FeeType::create([
            'name_ar' => 'معلوم التسجيل والترسيم',
            'price' => 70.00,
            'ledger_category' => CashTransaction::CATEGORY_REGISTRATION_FEE,
            'is_active' => true,
        ]);

        // تلميذ 1: دفع الترسيم كاملاً (70 د.ت)
        $enrollment1 = $this->makeEnrollment($year);
        $fee1 = StudentFee::create([
            'enrollment_id' => $enrollment1->id,
            'fee_type_id' => $regFeeType->id,
            'description' => 'معلوم الترسيم',
            'amount_due' => 70.00,
            'due_date' => now()->toDateString(),
            'status' => 'paid',
        ]);
        $payment1 = \App\Models\Payment::create([
            'student_id' => $enrollment1->student_id,
            'enrollment_id' => $enrollment1->id,
            'amount' => 70.00,
            'payment_date' => now()->toDateString(),
            'method' => 'cash',
        ]);
        \App\Models\PaymentAllocation::create([
            'payment_id' => $payment1->id,
            'student_fee_id' => $fee1->id,
            'amount_allocated' => 70.00,
        ]);

        // تلميذ 2: دفع جزئي (30 من 70) -> لا يُحتسب كخالص
        $enrollment2 = $this->makeEnrollment($year);
        $fee2 = StudentFee::create([
            'enrollment_id' => $enrollment2->id,
            'fee_type_id' => $regFeeType->id,
            'description' => 'معلوم الترسيم',
            'amount_due' => 70.00,
            'due_date' => now()->toDateString(),
            'status' => 'partial',
        ]);
        $payment2 = \App\Models\Payment::create([
            'student_id' => $enrollment2->student_id,
            'enrollment_id' => $enrollment2->id,
            'amount' => 30.00,
            'payment_date' => now()->toDateString(),
            'method' => 'cash',
        ]);
        \App\Models\PaymentAllocation::create([
            'payment_id' => $payment2->id,
            'student_fee_id' => $fee2->id,
            'amount_allocated' => 30.00,
        ]);

        // تلميذ 3: دفع ملغى -> لا يُحتسب
        $enrollment3 = $this->makeEnrollment($year);
        $fee3 = StudentFee::create([
            'enrollment_id' => $enrollment3->id,
            'fee_type_id' => $regFeeType->id,
            'description' => 'معلوم الترسيم',
            'amount_due' => 70.00,
            'due_date' => now()->toDateString(),
            'status' => 'pending',
        ]);
        $payment3 = \App\Models\Payment::create([
            'student_id' => $enrollment3->student_id,
            'enrollment_id' => $enrollment3->id,
            'amount' => 70.00,
            'payment_date' => now()->toDateString(),
            'method' => 'cash',
            'cancelled_at' => now(),
        ]);
        \App\Models\PaymentAllocation::create([
            'payment_id' => $payment3->id,
            'student_fee_id' => $fee3->id,
            'amount_allocated' => 70.00,
        ]);

        // تلميذ 4: بدون أي دفع
        $this->makeEnrollment($year);

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        // الإجمالي: 4 تلاميذ
        $this->assertEquals(4, $data['total_active_students']);
        // خالص الترسيم: 1 فقط (التلميذ الأول)
        $this->assertEquals(1, $data['paid_registration_count']);
        // غير خالص: 3 تلاميذ
        $this->assertEquals(3, $data['unpaid_registration_count']);
        // النسبة: 25.0%
        $this->assertEquals(25.0, (float) $data['registration_rate']);
    }
}
