<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Employee;
use App\Models\EmployeeLiability;
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

    // 2) دين عامل/إطار — بدون مسار API للتحصيل (زرع مباشر في الدفتر للاختبار فقط)
    public function test_employee_old_debt_collection_via_direct_ledger_seeding_not_api(): void
    {
        Sanctum::actingAs($this->adminUser());
        $year = $this->makeAcademicYear('2025-2026');
        $employee = Employee::create([
            'first_name' => 'سناء',
            'last_name' => 'المرابط',
            'staff_type' => 'worker',
            'is_active' => true,
        ]);
        $liability = EmployeeLiability::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $year->id,
            'original_year_label' => '2024/2025',
            'liability_type' => 'debt',
            'description' => 'دين عامل قديم',
            'original_amount' => 500,
            'status' => EmployeeLiability::STATUS_PENDING,
        ]);

        $this->assertSame(0.0, $liability->paid());
        $this->assertSame(500.0, $liability->outstanding());

        // زرع حركة تحصيل مباشرة في الدفتر (بيئة اختبار فقط) — لا يمثل مسار API
        CashTransaction::create([
            'transaction_date' => now()->toDateString(),
            'direction' => CashTransaction::DIRECTION_IN,
            'category' => CashTransaction::CATEGORY_OLD_LIABILITY_COLLECTION,
            'amount' => 200,
            'source_type' => $liability->getMorphClass(),
            'source_id' => $liability->getKey(),
            'academic_year_id' => $year->id,
            'description' => 'تحصيل دين عامل قديم (اختبار)',
        ]);

        $liability->refresh();
        $this->assertEqualsWithDelta(200, $liability->paid(), 0.01, 'paid() يجب أن يجمع old_liability_collection فقط');
        $this->assertEqualsWithDelta(300, $liability->outstanding(), 0.01);

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

        $emp = Employee::create(['first_name'=>'عامل','last_name'=>'قديم','staff_type'=>'worker','is_active'=>true]);
        EmployeeLiability::create([
            'employee_id'=>$emp->id,'academic_year_id'=>$currentYear->id,
            'original_year_label'=>'2024/2025','liability_type'=>'debt',
            'description'=>'دين عامل','original_amount'=>400,'status'=>EmployeeLiability::STATUS_PENDING,
        ]);

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

    // 4) بطاقة "ديون قديمة" — إثبات النقص بفشل واضح إن لم تكن الحقول موجودة
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
        $emp = Employee::create(['first_name'=>'إطار','last_name'=>'قديم','staff_type'=>'worker','is_active'=>true]);
        EmployeeLiability::create([
            'employee_id'=>$emp->id,'academic_year_id'=>$year->id,
            'original_year_label'=>'2024/2025','liability_type'=>'debt',
            'description'=>'دين إطار بطاقة','original_amount'=>350,'status'=>EmployeeLiability::STATUS_PENDING,
        ]);

        $data = app(DashboardService::class)->getDashboardData(true);
        $this->assertArrayHasKey('prior_debt_summary', $data);
        $summary = $data['prior_debt_summary'];

        // يجب وجود هيكل البطاقة
        $this->assertArrayHasKey('student_details', $summary);
        $this->assertArrayHasKey('employee_details', $summary);

        // الحقول المطلوبة للعرض — إن غابت فالاختبار يفشل بوضوح ويشير إلى ضرورة تعديل DashboardService
        $s = $summary['student_details'][0] ?? null;
        $this->assertNotNull($s, 'student_details فارغ');
        foreach (['type','created_at','original_year_label'] as $field) {
            // نتحقق من وجود المفتاح أو مرادفه (debt_type لـ type)
            $has = array_key_exists($field, $s) || ($field==='type' && array_key_exists('debt_type', $s));
            if (!$has) {
                $this->fail("بطاقة 'ديون قديمة' ناقصة الحقل المطلوب للطالب: {$field} — يحتاج تعديل DashboardService::priorDebtSummary لإضافته (لا يُعتبر غيابه نجاحاً).");
            }
        }
        $e = $summary['employee_details'][0] ?? null;
        $this->assertNotNull($e, 'employee_details فارغ');
        foreach (['type','created_at','original_year_label'] as $field) {
            $key = $field==='type' ? 'liability_type' : $field;
            if (!array_key_exists($key, $e)) {
                $this->fail("بطاقة 'ديون قديمة' ناقصة الحقل المطلوب للإطار: {$field} (key: {$key}) — يحتاج تعديل DashboardService.");
            }
        }
        // الحقول المالية الأساسية يجب أن تكون موجودة دائماً
        foreach (['original_amount','paid_amount','outstanding_amount'] as $k) {
            $this->assertArrayHasKey($k, $s);
            $this->assertArrayHasKey($k, $e);
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

    private function invokeCashFigures(DashboardService $service, ?string $from, string $to): array
    {
        $ref = new \ReflectionMethod($service, 'cashFigures');
        $ref->setAccessible(true);
        return $ref->invoke($service, $from, $to);
    }
}
