<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\Level;
use App\Models\ManualStudentDebt;
use App\Models\OldEmployeeDebt;
use App\Models\OldEmployeeDebtCollection;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OldDebtFinancialRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicYear $year;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->today = now()->toDateString();

        $this->year = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-08-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);

        config(['permissions.super_roles' => ['admin']]);

        $role = Role::create([
            'name' => 'admin',
            'display_name' => 'مدير النظام',
        ]);

        $p1 = Permission::create(['name' => 'manage_treasury', 'display_name' => 'إدارة الخزينة', 'group' => 'finance']);
        $p2 = Permission::create(['name' => 'view_reports', 'display_name' => 'عرض التقارير', 'group' => 'finance']);
        $p3 = Permission::create(['name' => 'manage_payments', 'display_name' => 'إدارة الاستخلاص', 'group' => 'finance']);
        $role->permissions()->attach([$p1->id, $p2->id, $p3->id]);

        $this->admin = User::create([
            'role_id' => $role->id,
            'first_name' => 'Admin',
            'last_name' => 'User',
            'username' => 'admin_test',
            'email' => 'admin_test@providence.tn',
            'password' => bcrypt('Password123#'),
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->admin);
    }

    /**
     * القاعدة 1: استخلاص دين قديم لتلميذ يدخل الخزينة ويزيد الرصيد ولا يدخل في المداخيل التشغيلية أو الدخل الصافي.
     */
    public function test_student_old_debt_collection_increases_treasury_balance_and_does_not_affect_operating_income_or_net_income(): void
    {
        $student = Student::create([
            'student_code' => 'PRV-OLD-01',
            'first_name' => 'سامي',
            'last_name' => 'القديم',
        ]);
        $level = Level::create(['name' => 'سنة أولى', 'code' => 'L1']);
        $section = Section::create(['name' => 'أ', 'code' => '1A', 'level_id' => $level->id]);
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => $this->today,
            'status' => 'active',
        ]);

        $studentDebt = ManualStudentDebt::create([
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'original_year_label' => '2025-2026',
            'debt_type' => 'tuition',
            'description' => 'متخلد تعليم سابق',
            'original_amount' => 150.00,
            'status' => ManualStudentDebt::STATUS_PENDING,
        ]);

        $bridgeFee = StudentFee::create([
            'enrollment_id' => $enrollment->id,
            'amount_due' => 150.00,
            'direct_paid_amount' => 0.00,
            'due_date' => $this->today,
            'status' => 'pending',
            'description' => 'دَين قديم: متخلد تعليم سابق',
        ]);
        $studentDebt->update(['source_student_fee_id' => $bridgeFee->id]);

        // استخلاص الدين القديم بمبلغ 150 د.ت
        $collectionService = app(CollectionService::class);
        $collectionService->collect([
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'months' => [],
            'items' => [],
            'club_items' => [],
            'prior_allocations' => [[
                'manual_student_debt_id' => $studentDebt->id,
                'amount' => 150.00,
            ]],
            'payment_date' => $this->today,
            'method' => 'cash',
            'notes' => 'خلاص دين قديم',
        ], $this->admin->id);

        // 1. فحص لوحة القيادة (Dashboard)
        $dashRes = $this->getJson('/api/dashboard')->assertOk()->json('data');
        $cash = $dashRes['cash']['all_time'];
        $this->assertEquals(0.00, (float) $cash['income'], 'مداخيل التشغيل للسنة الحالية يجب أن تبقى 0');
        $this->assertEquals(0.00, (float) $cash['net_income'], 'الدخل الصافي للسنة الحالية يجب أن يبقى 0');
        $this->assertEquals(150.00, (float) $cash['old_debt_collections'], 'تحصيل الديون القديمة يجب أن يسجل 150');
        $this->assertEquals(150.00, (float) $cash['cash_in'], 'المقبوضات النقدية الداخلة يجب أن تكون 150');
        $this->assertEquals(150.00, (float) $cash['balance'], 'رصيد الخزينة يجب أن يزيد بمقدار 150');
        $this->assertEquals(150.00, (float) $dashRes['treasury_balance'], 'رصيد الخزينة الإجمالي يجب أن يكون 150');

        // 2. فحص تقرير الدخل الصافي (Net Income Report)
        $finRes = $this->getJson('/api/reports/net-income?date='.$this->today)->assertOk()->json();
        // 3. فحص دفتر يومية الخزينة (Treasury Daybook)
        $dayRes = $this->getJson('/api/reports/treasury-daybook?date='.$this->today.'&date_to='.$this->today)->assertOk()->json();
        $this->assertEquals(0.00, (float) $dayRes['summary']['income']['total'], 'مداخيل اليومية = 0');
        $this->assertEquals(0.00, (float) $dayRes['summary']['net_income'], 'صافي دخل اليومية = 0');
        $this->assertEquals(150.00, (float) $dayRes['summary']['prior_year_debt'], 'تحصيل ديون سابقة باليومية = 150');
        $this->assertEquals(150.00, (float) $dayRes['summary']['balance'], 'رصيد اليومية = 150');

        // 4. فحص سجل الخزينة وحركاتها (Treasury History)
        $tresRes = $this->getJson('/api/treasury/history?date_from='.$this->today.'&date_to='.$this->today)->assertOk()->json();
        $this->assertEquals(0.00, (float) $tresRes['summary']['income'], 'مداخيل الخزينة = 0');
        $this->assertEquals(0.00, (float) $tresRes['summary']['net_income'], 'صافي دخل الخزينة = 0');
        $this->assertEquals(150.00, (float) $tresRes['summary']['prior_year_debt'], 'ديون سابقة بالخزينة = 150');
        $this->assertEquals(150.00, (float) $tresRes['summary']['balance'], 'رصيد الخزينة = 150');
    }

    /**
     * القاعدة 2: استخلاص دين قديم لإطار يدخل الخزينة ويزيد الرصيد ولا يدخل في المداخيل التشغيلية أو الدخل الصافي.
     */
    public function test_employee_old_debt_collection_increases_treasury_balance_and_does_not_affect_operating_income_or_net_income(): void
    {
        $employee = Employee::create([
            'first_name' => 'علي',
            'last_name' => 'المعلم',
            'role' => 'معلم',
        ]);

        $empDebt = OldEmployeeDebt::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $this->year->id,
            'original_year_label' => '2025-2026',
            'debt_type' => 'debt',
            'description' => 'سلفة قديمة من السنة الماضية',
            'original_amount' => 200.00,
            'status' => OldEmployeeDebt::STATUS_PENDING,
        ]);

        // استخلاص دين الإطار بمبلغ 200 د.ت
        $collection = OldEmployeeDebtCollection::create([
            'employee_opening_debt_id' => $empDebt->id,
            'amount' => 200.00,
            'payment_date' => $this->today,
            'method' => 'cash',
            'notes' => 'خلاص دين قديم نقداً',
            'collected_by' => $this->admin->id,
        ]);

        $ledger = app(LedgerService::class);
        $ledger->recordOldEmployeeDebtCollection($collection);
        $empDebt->syncStatus();

        // 1. لوحة القيادة
        $dashRes = $this->getJson('/api/dashboard')->assertOk()->json('data');
        $cash = $dashRes['cash']['all_time'];
        $this->assertEquals(0.00, (float) $cash['income']);
        $this->assertEquals(0.00, (float) $cash['net_income']);
        $this->assertEquals(200.00, (float) $cash['old_debt_collections']);
        $this->assertEquals(200.00, (float) $cash['cash_in']);
        $this->assertEquals(200.00, (float) $cash['balance']);

        // 2. تقرير الدخل الصافي
        $finRes = $this->getJson('/api/reports/net-income?date='.$this->today)->assertOk()->json();
        $this->assertEquals(0.00, (float) $finRes['day']['income']['total']);
        $this->assertEquals(0.00, (float) $finRes['day']['net_income']);
        $this->assertEquals(200.00, (float) $finRes['day']['prior_year_debt']);
        $this->assertEquals(200.00, (float) $finRes['day']['balance']);

        // 3. دفتر يومية الخزينة
        $dayRes = $this->getJson('/api/reports/treasury-daybook?date='.$this->today.'&date_to='.$this->today)->assertOk()->json();
        $this->assertEquals(0.00, (float) $dayRes['summary']['income']['total']);
        $this->assertEquals(0.00, (float) $dayRes['summary']['net_income']);
        $this->assertEquals(200.00, (float) $dayRes['summary']['prior_year_debt']);
        $this->assertEquals(200.00, (float) $dayRes['summary']['balance']);

        // 4. سجل الخزينة
        $tresRes = $this->getJson('/api/treasury/history?date_from='.$this->today.'&date_to='.$this->today)->assertOk()->json();
        $this->assertEquals(0.00, (float) $tresRes['summary']['income']);
        $this->assertEquals(0.00, (float) $tresRes['summary']['net_income']);
        $this->assertEquals(200.00, (float) $tresRes['summary']['prior_year_debt']);
        $this->assertEquals(200.00, (float) $tresRes['summary']['balance']);
    }

    /**
     * القاعدة 3: تجربة مختلطة تجمع بين مداخيل السنة النشطة وديون قديمة لتلميذ وإطار تتطابق في كل الشاشات.
     */
    public function test_mixed_student_and_employee_old_debt_collections_reconcile_across_all_financial_screens(): void
    {
        $student = Student::create(['student_code' => 'PRV-002', 'first_name' => 'محمد', 'last_name' => 'علي']);
        $level = Level::create(['name' => 'سنة ثانية', 'code' => 'L2']);
        $section = Section::create(['name' => 'ب', 'code' => '2B', 'level_id' => $level->id]);
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => $this->today,
            'status' => 'active',
        ]);

        $feeCat = FeeCategory::create(['name' => 'تمدرس', 'code' => 'tuition']);
        $feeType = FeeType::create([
            'name_ar' => 'معلوم شهري',
            'name_fr' => 'Frais mensuels',
            'is_active' => true,
        ]);

        // خطة الرسوم الشهرية
        FeePlan::create([
            'fee_category_id' => $feeCat->id,
            'academic_year_id' => $this->year->id,
            'level_id' => $level->id,
            'name' => 'معلوم شهري',
            'amount' => 120.00,
            'frequency' => 'monthly',
        ]);

        // 1. استخلاص معلوم دراسي للسنة الحالية بمبلغ 120 د.ت
        $collectionService = app(CollectionService::class);
        $collectionService->collect([
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'months' => ['2026-09'],
            'items' => [[
                'fee_type_id' => $feeType->id,
                'amount' => 120.00,
            ]],
            'club_items' => [],
            'prior_allocations' => [],
            'payment_date' => $this->today,
            'method' => 'cash',
        ], $this->admin->id);

        // 2. استخلاص دين قديم لتلميذ بمبلغ 100 د.ت
        $studentDebt = ManualStudentDebt::create([
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'original_year_label' => '2025-2026',
            'debt_type' => 'tuition',
            'description' => 'دين متخلد',
            'original_amount' => 100.00,
            'status' => ManualStudentDebt::STATUS_PENDING,
        ]);
        $bridgeFee = StudentFee::create([
            'enrollment_id' => $enrollment->id,
            'amount_due' => 100.00,
            'direct_paid_amount' => 0.00,
            'due_date' => $this->today,
            'status' => 'pending',
            'description' => 'دَين قديم: دين متخلد',
        ]);
        $studentDebt->update(['source_student_fee_id' => $bridgeFee->id]);

        $collectionService->collect([
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'months' => [],
            'items' => [],
            'club_items' => [],
            'prior_allocations' => [[
                'manual_student_debt_id' => $studentDebt->id,
                'amount' => 100.00,
            ]],
            'payment_date' => $this->today,
            'method' => 'cash',
        ], $this->admin->id);

        // 3. استخلاص دين قديم لإطار بمبلغ 80 د.ت
        $employee = Employee::create(['first_name' => 'خالد', 'last_name' => 'المهندس', 'role' => 'إداري']);
        $empDebt = OldEmployeeDebt::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $this->year->id,
            'original_year_label' => '2025-2026',
            'debt_type' => 'debt',
            'description' => 'دين قديم',
            'original_amount' => 80.00,
            'status' => OldEmployeeDebt::STATUS_PENDING,
        ]);
        $collection = OldEmployeeDebtCollection::create([
            'employee_opening_debt_id' => $empDebt->id,
            'amount' => 80.00,
            'payment_date' => $this->today,
            'method' => 'cash',
            'collected_by' => $this->admin->id,
        ]);
        $ledger = app(LedgerService::class);
        $ledger->recordOldEmployeeDebtCollection($collection);
        $empDebt->syncStatus();

        // A. لوحة القيادة
        $dashRes = $this->getJson('/api/dashboard')->assertOk()->json('data');
        $cash = $dashRes['cash']['all_time'];
        $this->assertEquals(120.00, (float) $cash['income'], 'مداخيل التشغيل = 120');
        $this->assertEquals(180.00, (float) $cash['old_debt_collections'], 'ديون قديمة = 180');
        $this->assertEquals(300.00, (float) $cash['cash_in'], 'إجمالي النقد = 300');
        $this->assertEquals(120.00, (float) $cash['net_income'], 'الدخل الصافي = 120');
        $this->assertEquals(300.00, (float) $cash['balance'], 'رصيد الخزينة = 300');

        // B. تقرير الدخل الصافي
        $finRes = $this->getJson('/api/reports/net-income?date='.$this->today)->assertOk()->json();
        $this->assertEquals(120.00, (float) $finRes['day']['income']['total']);
        $this->assertEquals(180.00, (float) $finRes['day']['prior_year_debt']);
        $this->assertEquals(120.00, (float) $finRes['day']['net_income']);
        $this->assertEquals(300.00, (float) $finRes['day']['balance']);

        // C. دفتر يومية الخزينة
        $dayRes = $this->getJson('/api/reports/treasury-daybook?date='.$this->today.'&date_to='.$this->today)->assertOk()->json();
        $this->assertEquals(120.00, (float) $dayRes['summary']['income']['total']);
        $this->assertEquals(180.00, (float) $dayRes['summary']['prior_year_debt']);
        $this->assertEquals(120.00, (float) $dayRes['summary']['net_income']);
        $this->assertEquals(300.00, (float) $dayRes['summary']['balance']);

        // D. سجل الخزينة
        $tresRes = $this->getJson('/api/treasury/history?date_from='.$this->today.'&date_to='.$this->today)->assertOk()->json();
        $this->assertEquals(120.00, (float) $tresRes['summary']['income']);
        $this->assertEquals(180.00, (float) $tresRes['summary']['prior_year_debt']);
        $this->assertEquals(120.00, (float) $tresRes['summary']['net_income']);
        $this->assertEquals(300.00, (float) $tresRes['summary']['balance']);
    }

    /**
     * القاعدة 4: إلغاء استخلاص دين قديم يخصم النقد المسترجع من الخزينة تلقائياً ويعيد الدين لمستحقه دون أي أثر على الدخل الصافي.
     */
    public function test_cancellation_of_old_debt_payment_safely_reverses_cash_and_restores_debt_without_touching_net_income(): void
    {
        $student = Student::create(['student_code' => 'PRV-CANCEL-01', 'first_name' => 'ياسين', 'last_name' => 'الملغي']);
        $level = Level::create(['name' => 'سنة ثالثة', 'code' => 'L3']);
        $section = Section::create(['name' => 'ج', 'code' => '3C', 'level_id' => $level->id]);
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => $this->today,
            'status' => 'active',
        ]);

        $studentDebt = ManualStudentDebt::create([
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'original_year_label' => '2025-2026',
            'debt_type' => 'tuition',
            'description' => 'دين قديم للاختبار',
            'original_amount' => 250.00,
            'status' => ManualStudentDebt::STATUS_PENDING,
        ]);
        $bridgeFee = StudentFee::create([
            'enrollment_id' => $enrollment->id,
            'amount_due' => 250.00,
            'direct_paid_amount' => 0.00,
            'due_date' => $this->today,
            'status' => 'pending',
            'description' => 'دَين قديم: دين قديم للاختبار',
        ]);
        $studentDebt->update(['source_student_fee_id' => $bridgeFee->id]);

        $collectionService = app(CollectionService::class);
        $receipt = $collectionService->collect([
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'months' => [],
            'items' => [],
            'club_items' => [],
            'prior_allocations' => [[
                'manual_student_debt_id' => $studentDebt->id,
                'amount' => 250.00,
            ]],
            'payment_date' => $this->today,
            'method' => 'cash',
        ], $this->admin->id);

        $paymentId = $receipt['payment_id'];

        // التأكد من تسجيل الـ 250 في الخزينة
        $dashBefore = $this->getJson('/api/dashboard')->assertOk()->json('data.cash.all_time');
        $this->assertEquals(250.00, (float) $dashBefore['balance']);
        $this->assertEquals(0.00, (float) $dashBefore['net_income']);

        // إلغاء الدفعة
        $this->postJson('/api/payments/'.$paymentId.'/cancel', [
            'reason' => 'إلغاء لخطأ في تسجيل الدفعة',
        ])->assertOk();

        // 1. فحص لوحة القيادة بعد الإلغاء: عودة رصيد الخزينة لـ 0، والدخل الصافي بقي 0 بلا تغيير
        $dashAfter = $this->getJson('/api/dashboard')->assertOk()->json('data.cash.all_time');
        $this->assertEquals(0.00, (float) $dashAfter['balance'], 'رصيد الخزينة عاد إلى 0');
        $this->assertEquals(0.00, (float) $dashAfter['cash_in'], 'المقبوضات عادت إلى 0');
        $this->assertEquals(0.00, (float) $dashAfter['old_debt_collections'], 'تحصيل الديون القديمة عاد إلى 0');
        $this->assertEquals(0.00, (float) $dashAfter['net_income'], 'الدخل الصافي بقي 0');

        // 2. فحص تقرير الدخل الصافي بعد الإلغاء
        $finRes = $this->getJson('/api/reports/net-income?date='.$this->today)->assertOk()->json();
        $this->assertEquals(0.00, (float) $finRes['day']['prior_year_debt'], 'بند الديون القديمة = 0');
        $this->assertEquals(0.00, (float) $finRes['day']['balance'], 'رصيد الخزينة = 0');
        $this->assertEquals(0.00, (float) $finRes['day']['net_income'], 'الدخل الصافي = 0');

        // 3. فحص استعادة حالة الدين القديم
        $this->assertEquals(250.00, (float) $studentDebt->fresh()->outstanding(), 'الدين عاد مستحقاً بالكامل 250 د.ت');
    }
}
