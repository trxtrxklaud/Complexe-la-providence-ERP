<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\ManualStudentDebt;
use App\Models\Student;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * اختبارات التحقق المحاسبي للديون القديمة — قراءة وتوثيق فقط.
 * لا يُعدّل أي Service/Controller/Model/route.
 */
class OldDebtAccountingTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser()
    {
        $user = $this->makeUser('admin');
        $user->update(['is_active' => true]);
        return $user;
    }

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
        $enrollment = $this->makeEnrollment($currentYear, $student);
        return [$student, $currentYear, $enrollment];
    }

    // 1) دين تلميذ قديم — عبر مسار التحصيل الموجود فعلياً
    public function test_student_old_debt_collection_is_prior_year_debt_in_and_excluded_from_income(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$student, $currentYear, $enrollment] = $this->studentWithTwoYears();

        $debtResp = $this->postJson('/api/manual-debts', [
            'student_id' => $student->id,
            'academic_year_id' => $currentYear->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'tuition',
            'description' => 'متخلدات سابقة للاختبار',
            'original_amount' => 1000,
        ])->assertCreated();
        $debtId = $debtResp->json('id');

        $cashBefore = (float) CashTransaction::whereNull('cancelled_at')->whereIn('category', CashTransaction::OLD_DEBT_COLLECTION_CATEGORIES)->sum('amount');
        $incomeBefore = (float) CashTransaction::whereNull('cancelled_at')->whereIn('category', CashTransaction::INCOME_CATEGORIES)->sum('amount');

        $this->postJson('/api/payments/collect', [
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'prior_allocations' => [['manual_student_debt_id' => $debtId, 'amount' => 300]],
        ])->assertCreated();

        $this->assertDatabaseHas('cash_transactions', [
            'category' => CashTransaction::CATEGORY_PRIOR_YEAR_DEBT,
            'direction' => CashTransaction::DIRECTION_IN,
            'amount' => 300,
        ]);
        $this->assertDatabaseMissing('cash_transactions', [
            'category' => CashTransaction::CATEGORY_MONTHLY_FEE,
            'amount' => 300,
        ]);

        $cashAfter = (float) CashTransaction::whereNull('cancelled_at')->whereIn('category', CashTransaction::OLD_DEBT_COLLECTION_CATEGORIES)->sum('amount');
        $incomeAfter = (float) CashTransaction::whereNull('cancelled_at')->whereIn('category', CashTransaction::INCOME_CATEGORIES)->sum('amount');

        $this->assertEqualsWithDelta(300, $cashAfter - $cashBefore, 0.01, 'cash_in / old_debt_collections يجب أن يزيد بمقدار التحصيل');
        $this->assertEqualsWithDelta(0, $incomeAfter - $incomeBefore, 0.01, 'current_year_income يجب ألا يزيد بتحصيل دين قديم');

        // net_income = income - expenses — يجب ألا يزيد
        $service = app(DashboardService::class);
        $figures = $this->invokeCashFigures($service, null, now()->toDateString());
        // net_income مستقل عن old_debt_collections
        $this->assertEqualsWithDelta($figures['income'] - $figures['expenses'], $figures['net_income'], 0.01);
        // balance = cash_in - expenses - withdrawals
        $this->assertEqualsWithDelta($figures['cash_in'] - $figures['expenses'] - $figures['withdrawals'], $figures['balance'], 0.01);
    }

    // 2) تحصيل دين قديم كحركة نقدية موروثة (زرع مباشر في الدفتر للاختبار)
    public function test_employee_old_debt_collection_via_direct_ledger_seeding_not_api(): void
    {
        Sanctum::actingAs($this->adminUser());
        $year = $this->makeAcademicYear('2025-2026');

        // زرع حركة تحصيل مباشرة في الدفتر (بيئة اختبار فقط)
        CashTransaction::create([
            'transaction_date' => now()->toDateString(),
            'direction' => CashTransaction::DIRECTION_IN,
            'category' => CashTransaction::CATEGORY_OLD_LIABILITY_COLLECTION,
            'amount' => 200,
            'source_type' => 'App\\Models\\EmployeeLiability',
            'source_id' => 999,
            'academic_year_id' => $year->id,
            'description' => 'تحصيل دين عامل قديم (اختبار)',
        ]);

        $service = app(DashboardService::class);
        $data = $service->getDashboardData(true);
        $this->assertArrayHasKey('prior_debt_summary', $data);
        // old_debt_collections يجب أن يحوي 200
        $this->assertEqualsWithDelta(200, $data['prior_debt_summary']['total_collected'] ?? 0, 0.01);
        // current_year_income لا يحويه
        $this->assertEqualsWithDelta(0, $data['cash']['all_time']['current_year_income'] ?? $data['cash']['all_time']['income'], 0.01);
    }

    // 3) عدم الازدواج
    public function test_no_double_counting_before_and_after_collection(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$student, $currentYear, $enrollment] = $this->studentWithTwoYears();
        $this->postJson('/api/manual-debts', [
            'student_id' => $student->id,
            'academic_year_id' => $currentYear->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'tuition',
            'description' => 'دين للازدواج',
            'original_amount' => 800,
        ])->assertCreated();

        $service = app(DashboardService::class);
        $before = $service->getDashboardData(true);
        $cashInBefore = $before['cash']['all_time']['cash_in'] ?? 0;
        $oldBefore = $before['cash']['all_time']['old_debt_collections'] ?? 0;
        $this->assertEqualsWithDelta(0, $cashInBefore - $before['cash']['all_time']['current_year_income'], 0.01, 'قبل التحصيل cash_in = current فقط');
        $this->assertEqualsWithDelta(0, $oldBefore, 0.01);

        // تحصيل جزئي واحد
        $debtId = ManualStudentDebt::first()->id;
        $this->postJson('/api/payments/collect', [
            'student_id'=>$student->id,'enrollment_id'=>$enrollment->id,
            'payment_date'=>'2025-09-06','method'=>'cash',
            'prior_allocations'=>[['manual_student_debt_id'=>$debtId,'amount'=>300]],
        ])->assertCreated();

        $after = $service->getDashboardData(true);
        $this->assertEqualsWithDelta(300, $after['cash']['all_time']['old_debt_collections'], 0.01);
        $this->assertEqualsWithDelta(300, $after['cash']['all_time']['cash_in'] - $after['cash']['all_time']['current_year_income'], 0.01);
        // يظهر مرة واحدة فقط
        $this->assertEqualsWithDelta(300, $after['prior_debt_summary']['total_collected'], 0.01);
    }

    // 4) بطاقة "ديون قديمة" — إثبات الحقول المطلوبة للتلاميذ
    public function test_card_displays_required_fields_or_fails_clearly(): void
    {
        Sanctum::actingAs($this->adminUser());
        $year = $this->makeAcademicYear('2025-2026');
        $student = Student::create(['student_code'=>'STU-CARD-'.uniqid(),'first_name'=>'ليلى','last_name'=>'النجار','gender'=>'female','status'=>'active']);
        $this->makeEnrollment($year, $student);
        ManualStudentDebt::create([
            'student_id'=>$student->id,'academic_year_id'=>$year->id,
            'original_year_label'=>'2024/2025','debt_type'=>'tuition',
            'description'=>'دين بطاقة','original_amount'=>600,'status'=>ManualStudentDebt::STATUS_PENDING,
        ]);

        $data = app(DashboardService::class)->getDashboardData(true);
        $this->assertArrayHasKey('prior_debt_summary', $data);
        $summary = $data['prior_debt_summary'];

        // يجب وجود هيكل البطاقة
        $this->assertArrayHasKey('student_details', $summary);
        $this->assertArrayHasKey('employee_details', $summary);

        // الحقول المطلوبة للعرض
        $s = $summary['student_details'][0] ?? null;
        $this->assertNotNull($s, 'student_details فارغ');
        foreach (['type','created_at','original_year_label'] as $field) {
            $has = array_key_exists($field, $s) || ($field==='type' && array_key_exists('debt_type', $s));
            if (!$has) {
                $this->fail("بطاقة 'ديون قديمة' ناقصة الحقل المطلوب للطالب: {$field} — يحتاج تعديل DashboardService::priorDebtSummary لإضافته.");
            }
        }
        foreach (['original_amount','paid_amount','outstanding_amount'] as $k) {
            $this->assertArrayHasKey($k, $s);
        }
    }

    // 5) العلاقة لا تعتمد على description
    public function test_classification_does_not_depend_on_description(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$student, $currentYear, $enrollment] = $this->studentWithTwoYears();
        $a = $this->postJson('/api/manual-debts', [
            'student_id'=>$student->id,'academic_year_id'=>$currentYear->id,
            'original_year_label'=>'2024/2025','debt_type'=>'tuition',
            'description'=>'monthly_fee وهمي — نص مضلل',
            'original_amount'=>700,
        ])->assertCreated()->json('id');

        $this->postJson('/api/payments/collect', [
            'student_id'=>$student->id,'enrollment_id'=>$enrollment->id,
            'payment_date'=>'2025-09-07','method'=>'cash',
            'prior_allocations'=>[['manual_student_debt_id'=>$a,'amount'=>200]],
        ])->assertCreated();

        $this->assertDatabaseHas('cash_transactions', [
            'category'=>CashTransaction::CATEGORY_PRIOR_YEAR_DEBT,
            'direction'=>CashTransaction::DIRECTION_IN,
            'amount'=>200,
        ]);
        // لم يُصنف كـ monthly_fee رغم احتواء الوصف عليه
        $countMonthly = CashTransaction::where('category', CashTransaction::CATEGORY_MONTHLY_FEE)->where('amount',200)->count();
        $this->assertSame(0, $countMonthly, 'التصنيف يجب ألا يعتمد على description');
    }

    public function test_daybook_shows_old_debt_line_and_balance_after_withdrawals(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$student, $currentYear, $enrollment] = $this->studentWithTwoYears();
        $date = now()->toDateString();

        $debtId = $this->postJson('/api/manual-debts', [
            'student_id' => $student->id,
            'academic_year_id' => $currentYear->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'tuition',
            'description' => 'دين يومية',
            'original_amount' => 300,
        ])->assertCreated()->json('id');

        $this->postJson('/api/payments/collect', [
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'payment_date' => $date,
            'method' => 'cash',
            'prior_allocations' => [['manual_student_debt_id' => $debtId, 'amount' => 300]],
        ])->assertCreated();

        // سحب 20 في نفس اليوم
        $this->postJson('/api/treasury/withdrawals', [
            'amount' => 20,
            'withdrawn_at' => $date,
            'type' => 'test',
            'note' => 'اختبار رصيد',
        ])->assertCreated();

        $res = $this->getJson('/api/reports/treasury-daybook?date='.$date.'&details=1')->assertOk()->json();
        $day = collect($res['days'])->firstWhere('date', $date);
        $this->assertNotNull($day, 'يجب أن يعود اليوم في الكشف');

        // القواعد الخمس
        $this->assertEqualsWithDelta(300, $day['prior_year_debt'] ?? 0, 0.01, 'تحصيل الدين القديم يظهر في بند prior_year_debt');
        $this->assertEqualsWithDelta(0, $day['income']['total'] ?? 0, 0.01, 'لا يدخل current_year_income');
        $this->assertEqualsWithDelta(0, $day['net_income'] ?? 0, 0.01, 'لا يدخل net_income');
        $this->assertEqualsWithDelta(280, $day['balance'] ?? 0, 0.01, 'يدخل في الرصيد بعد السحوبات: 300 - 20');
        // cash_in مشتق: income + prior
        $cashIn = ($day['income']['total'] ?? 0) + ($day['prior_year_debt'] ?? 0);
        $this->assertEqualsWithDelta(300, $cashIn, 0.01, 'يدخل cash_in');
        // تفصيل prior_debt يحوي السطر
        $this->assertNotEmpty($day['details']['prior_debt'] ?? []);
        $this->assertEqualsWithDelta(300, $day['details']['prior_debt'][0]['amount'] ?? 0, 0.01);
    }

    private function invokeCashFigures(DashboardService $service, ?string $from, string $to): array
    {
        $ref = new \ReflectionMethod($service, 'cashFigures');
        $ref->setAccessible(true);
        return $ref->invoke($service, $from, $to);
    }

    // 6) كشف الخزينة والدخل الصافي يشملان دين الإطار مع دين التلميذ — ولا يخلطان خلاص المستحقّات
    public function test_daybook_and_net_income_include_employee_debt_with_student_debt(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$student, $currentYear, $enrollment] = $this->studentWithTwoYears();
        $date = now()->toDateString();

        // دين تلميذ 50 → تحصيل عبر المسار الفعلي
        $debtId = $this->postJson('/api/manual-debts', [
            'student_id' => $student->id,
            'academic_year_id' => $currentYear->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'tuition',
            'description' => 'دين تلميذ قديم',
            'original_amount' => 50,
        ])->assertCreated()->json('id');

        $this->postJson('/api/payments/collect', [
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'payment_date' => $date,
            'method' => 'cash',
            'prior_allocations' => [['manual_student_debt_id' => $debtId, 'amount' => 50]],
        ])->assertCreated();

        // حركة تحصيل دين قديم موروثة (444)
        $frameTxn = CashTransaction::create([
            'transaction_date' => $date,
            'direction' => CashTransaction::DIRECTION_IN,
            'category' => CashTransaction::CATEGORY_OLD_LIABILITY_COLLECTION,
            'amount' => 444,
            'source_type' => 'App\\Models\\EmployeeLiability',
            'source_id' => 999,
            'academic_year_id' => $currentYear->id,
            'description' => 'دين إطار قديم',
        ]);

        // خلاص مستحقّات (out) يجب ألا يدخل prior_year_debt
        CashTransaction::create([
            'transaction_date' => $date,
            'direction' => CashTransaction::DIRECTION_OUT,
            'category' => CashTransaction::CATEGORY_OLD_LIABILITY_PAYMENT,
            'amount' => 999,
            'source_type' => 'App\\Models\\EmployeeLiability',
            'source_id' => 999,
            'academic_year_id' => $currentYear->id,
            'description' => 'خلاص مستحقّات — خارج البند',
        ]);

        // ---- كشف الخزينة ----
        $res = $this->getJson('/api/reports/treasury-daybook?date='.$date.'&details=1')->assertOk()->json();
        $day = collect($res['days'])->firstWhere('date', $date);
        $this->assertNotNull($day);

        $this->assertEqualsWithDelta(494, $day['prior_year_debt'] ?? 0, 0.01, 'prior_year_debt = تلميذ 50 + إطار 444');
        $this->assertEqualsWithDelta(0, $day['income']['total'] ?? 0, 0.01, 'لا يدخل income التشغيلي');
        $this->assertEqualsWithDelta(0, $day['net_income'] ?? 0, 0.01, 'لا يدخل net_income');
        $this->assertEqualsWithDelta(494, $day['balance'] ?? 0, 0.01, 'الرصيد يتضمن التحصيلين');

        // حركة الإطار تحت prior_debt ولا تسقط تحت expenses، وخلاص المستحقّات خارج البند
        $priorIds = array_column($day['details']['prior_debt'] ?? [], 'id');
        $expenseIds = array_column($day['details']['expenses'] ?? [], 'id');
        $frameTxnId = (int) $frameTxn->id;
        $this->assertContains($frameTxnId, $priorIds, 'حركة الإطار في bucket prior_debt');
        $this->assertNotContains($frameTxnId, $expenseIds, 'حركة الإطار ليست ضمن مصاريف اليوم');

        // ---- الدخل الصافي (تراكمي حتى التاريخ) ----
        $net = $this->getJson('/api/reports/net-income?date='.$date)->assertOk()->json();
        $this->assertEqualsWithDelta(494, $net['cumulative']['prior_year_debt'] ?? 0, 0.01, 'الدخل الصافي التراكمي = 494');
        $this->assertEqualsWithDelta(494, $net['cumulative']['balance'] ?? 0, 0.01, 'رصيد الدخل الصافي = 494');
        $this->assertEqualsWithDelta(0, $net['cumulative']['net_income'] ?? 0, 0.01, 'net_income لا يتأثر');
    }
}
