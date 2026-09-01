<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Employee;
use App\Models\EmployeeLiability;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\Level;
use App\Models\ManualStudentDebt;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * التنبيه غير الحاجب للديون القديمة عند الاستخلاص العائلي.
 *
 * GET /api/families/{family}/old-debts قراءة فقط في manual_student_debts:
 * لا ينشئ Payment ولا CashTransaction ولا يعدّل أي رصيد، والمتبقّي يُشتقّ من
 * توزيعات الدفعات غير الملغاة حصراً (قاعدة ManualStudentDebt::outstanding).
 */
class FamilyOldDebtWarningTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Guardian $guardian;
    protected Student $student1;
    protected Student $student2;
    protected Enrollment $enrollment1;
    protected Enrollment $enrollment2;
    protected AcademicYear $currentYear;

    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::create([
            'name' => 'manage_payments',
            'display_name' => 'إدارة التحصيل والدفعات',
            'group' => 'finance',
        ]);

        $role = Role::create([
            'name' => 'admin',
            'display_name' => 'مدير النظام',
        ]);
        $role->permissions()->attach($permission->id);

        $this->user = User::create([
            'role_id' => $role->id,
            'username' => 'admin_old_debt_warning',
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin_old_debt_warning@test.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $this->actingAs($this->user);

        // سنة سابقة لنقل الديون منها (الرسم الجسر) وسنة حالية نشطة.
        $oldYear = AcademicYear::create([
            'name' => '2025-2026',
            'start_date' => '2025-09-01',
            'end_date' => '2026-06-30',
            'is_active' => false,
        ]);

        $this->currentYear = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
            'is_current' => true,
        ]);

        $level = Level::create(['name' => 'المستوى الأول', 'code' => 'L1']);
        $section = Section::create(['level_id' => $level->id, 'name' => 'أ', 'code' => 'L1-A', 'capacity' => 30]);

        $this->guardian = Guardian::create([
            'first_name' => 'علي',
            'last_name' => 'بن صالح',
            'phone' => '99887766',
            'address' => 'قفصة',
        ]);

        $this->student1 = Student::create([
            'student_code' => 'PRV-OLDW-001',
            'first_name' => 'ياسين',
            'last_name' => 'بن صالح',
            'gender' => 'boy',
            'birth_date' => '2018-01-01',
            'guardian_phone' => '99887766',
        ]);

        $this->student2 = Student::create([
            'student_code' => 'PRV-OLDW-002',
            'first_name' => 'مريم',
            'last_name' => 'بن صالح',
            'gender' => 'girl',
            'birth_date' => '2019-05-05',
            'guardian_phone' => '99887766',
        ]);

        $this->guardian->students()->attach($this->student1->id);
        $this->guardian->students()->attach($this->student2->id);

        // لكل تلميذ تسجيل سابق (جسر الدين) وتسجيل حالي نشط.
        Enrollment::create([
            'student_id' => $this->student1->id,
            'academic_year_id' => $oldYear->id,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => '2025-09-01',
            'status' => 'active',
        ]);
        $this->enrollment1 = Enrollment::create([
            'student_id' => $this->student1->id,
            'academic_year_id' => $this->currentYear->id,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => '2026-09-01',
            'status' => 'active',
        ]);
        Enrollment::create([
            'student_id' => $this->student2->id,
            'academic_year_id' => $oldYear->id,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => '2025-09-01',
            'status' => 'active',
        ]);
        $this->enrollment2 = Enrollment::create([
            'student_id' => $this->student2->id,
            'academic_year_id' => $this->currentYear->id,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => '2026-09-01',
            'status' => 'active',
        ]);

        $feeCat = FeeCategory::create(['code' => 'TUITION', 'name' => 'معلوم التمدرس', 'is_recurring' => true]);
        FeePlan::create([
            'academic_year_id' => $this->currentYear->id,
            'fee_category_id' => $feeCat->id,
            'level_id' => $level->id,
            'name' => 'معلوم التمدرس الأساسي',
            'amount' => 190.00,
            'frequency' => 'monthly',
        ]);

        FeeType::create([
            'name_ar' => 'معلوم التمدرس',
            'name_fr' => 'Frais Scolarite',
            'code' => 'TUITION',
            'price' => 190.00,
            'is_recurring' => true,
        ]);
    }

    // ==================== Helpers ====================

    protected function oldDebtUrl(): string
    {
        return '/api/families/' . $this->guardian->id . '/old-debts';
    }

    /** إدخال دين قديم عبر المسار الرسمي (ينشئ الرسم الجسر بلا أثر نقدي). */
    protected function makeManualDebt(Student $student, float $amount, string $debtType = 'tuition'): ManualStudentDebt
    {
        $response = $this->postJson('/api/manual-debts', [
            'student_id' => $student->id,
            'academic_year_id' => $this->currentYear->id,
            'original_year_label' => '2025/2026',
            'debt_type' => $debtType,
            'description' => 'دين قديم للاختبار',
            'original_amount' => $amount,
        ]);

        $response->assertCreated();

        return ManualStudentDebt::findOrFail($response->json('id'));
    }

    /** تحصيل جزء من الدَّين عبر مسار متخلّدات السنوات السابقة. */
    protected function collectOnDebt(ManualStudentDebt $debt, float $amount): array
    {
        $response = $this->postJson('/api/manual-debts/' . $debt->id . '/collect', [
            'amount' => $amount,
            'payment_date' => '2026-08-20',
            'method' => 'cash',
        ]);

        $response->assertCreated();

        return $response->json();
    }

    // ==================== Tests ====================

    public function test_family_without_debts_returns_zero_count_and_total(): void
    {
        $response = $this->getJson($this->oldDebtUrl());

        $response->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('total', 0);

        $this->assertSame([], (array) $response->json('students'));
    }

    public function test_debt_of_125_reports_outstanding_amount_125(): void
    {
        $this->makeManualDebt($this->student1, 125);

        $response = $this->getJson($this->oldDebtUrl());

        $response->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('students.' . $this->student1->id . '.student_id', $this->student1->id)
            ->assertJsonPath('students.' . $this->student1->id . '.student_name', 'ياسين بن صالح')
            ->assertJsonPath('students.' . $this->student1->id . '.student_code', 'PRV-OLDW-001')
            ->assertJsonPath('students.' . $this->student1->id . '.has_debt', true)
            ->assertJsonPath('students.' . $this->student1->id . '.debts_count', 1);

        $this->assertEqualsWithDelta(125.0, (float) $response->json('students.' . $this->student1->id . '.amount'), 0.001);
        $this->assertEqualsWithDelta(125.0, (float) $response->json('total'), 0.001);
    }

    public function test_uncancelled_collection_reduces_outstanding(): void
    {
        $debt = $this->makeManualDebt($this->student1, 125);
        $this->collectOnDebt($debt, 50);

        $response = $this->getJson($this->oldDebtUrl());

        $response->assertOk()->assertJsonPath('count', 1);
        $this->assertEqualsWithDelta(75.0, (float) $response->json('students.' . $this->student1->id . '.amount'), 0.001);
        $this->assertEqualsWithDelta(75.0, (float) $response->json('total'), 0.001);
    }

    public function test_cancelled_payment_does_not_reduce_outstanding(): void
    {
        $debt = $this->makeManualDebt($this->student1, 125);
        $collected = $this->collectOnDebt($debt, 50);
        $paymentId = $collected['receipt']['payment_id'] ?? null;
        $this->assertNotNull($paymentId);

        $this->postJson('/api/payments/' . $paymentId . '/cancel', ['reason' => 'إلغاء تجريبي للتحقق'])
            ->assertOk();

        $response = $this->getJson($this->oldDebtUrl());

        $response->assertOk()->assertJsonPath('count', 1);
        $this->assertEqualsWithDelta(125.0, (float) $response->json('students.' . $this->student1->id . '.amount'), 0.001);
    }

    public function test_paid_status_debt_is_not_shown(): void
    {
        $debt = $this->makeManualDebt($this->student1, 125);
        $debt->update(['status' => ManualStudentDebt::STATUS_PAID]);

        $response = $this->getJson($this->oldDebtUrl());

        $response->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('total', 0);
        $this->assertSame([], (array) $response->json('students'));
    }

    public function test_fully_collected_debt_is_not_shown(): void
    {
        $debt = $this->makeManualDebt($this->student1, 125);
        $this->collectOnDebt($debt, 125);

        $debt->refresh();
        $this->assertSame(ManualStudentDebt::STATUS_PAID, $debt->status);

        $response = $this->getJson($this->oldDebtUrl());

        $response->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('total', 0);
        $this->assertSame([], (array) $response->json('students'));
    }

    public function test_employee_liability_is_not_reported_here(): void
    {
        $employee = Employee::create([
            'first_name' => 'محمد',
            'last_name' => 'العياري',
            'job_title' => 'عامل',
            'staff_type' => 'worker',
            'salary_type' => 'monthly',
            'monthly_salary' => 900,
            'is_active' => true,
        ]);

        EmployeeLiability::create([
            'employee_id' => $employee->id,
            'academic_year_id' => $this->currentYear->id,
            'original_year_label' => '2025/2026',
            'liability_type' => 'debt',
            'description' => 'مستحق قديم لإطار',
            'original_amount' => 500,
            'status' => EmployeeLiability::STATUS_PENDING,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson($this->oldDebtUrl());

        $response->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('total', 0);
        $this->assertSame([], (array) $response->json('students'));
    }

    public function test_get_creates_no_payment_or_cash_transaction(): void
    {
        $debt = $this->makeManualDebt($this->student1, 125);
        $this->collectOnDebt($debt, 50);

        // baseline: لا نفترض أن الجداول تبدأ بصفر — نقارن قبل وبعد.
        $paymentsBefore = DB::table('payments')->count();
        $allocationsBefore = DB::table('payment_allocations')->count();
        $cashBefore = DB::table('cash_transactions')->count();
        $debtsBefore = DB::table('manual_student_debts')->count();

        $this->getJson($this->oldDebtUrl())->assertOk();

        $this->assertSame($paymentsBefore, DB::table('payments')->count());
        $this->assertSame($allocationsBefore, DB::table('payment_allocations')->count());
        $this->assertSame($cashBefore, DB::table('cash_transactions')->count());
        $this->assertSame($debtsBefore, DB::table('manual_student_debts')->count());
    }

    public function test_individual_collection_succeeds_with_warning_present(): void
    {
        $debt = $this->makeManualDebt($this->student1, 125);

        // التنبيه ظاهر قبل الاستخلاص الفردي.
        $this->getJson($this->oldDebtUrl())->assertOk()->assertJsonPath('count', 1);

        // الاستخلاص الفردي (مسار شاشة الاستخلاص: prior_allocations على الدين
        // القديم نفسه) ينجح رغم ظهور التنبيه، ويخضع لنفس حراس القبض العادية.
        $response = $this->postJson('/api/payments/collect', [
            'student_id' => $this->student1->id,
            'enrollment_id' => $this->enrollment1->id,
            'payment_date' => '2026-08-20',
            'method' => 'cash',
            'prior_allocations' => [['manual_student_debt_id' => $debt->id, 'amount' => 40]],
        ]);

        $response->assertCreated();

        // التنبيه يبقى ظاهراً بالمتبقي الجديد: 125 − 40.
        $this->getJson($this->oldDebtUrl())->assertOk()->assertJsonPath('count', 1)
            ->assertJsonPath('students.' . $this->student1->id . '.amount', 85);
    }

    public function test_family_collection_succeeds_with_warning_present(): void
    {
        $this->makeManualDebt($this->student1, 125);

        // التنبيه ظاهر قبل الاستخلاص الجماعي.
        $this->getJson($this->oldDebtUrl())->assertOk()->assertJsonPath('count', 1);

        $response = $this->postJson('/api/families/' . $this->guardian->id . '/collect', [
            'students_allocations' => [
                ['student_id' => $this->student1->id, 'enrollment_id' => $this->enrollment1->id, 'months' => ['2026-09']],
                ['student_id' => $this->student2->id, 'enrollment_id' => $this->enrollment2->id, 'months' => ['2026-09']],
            ],
            'payment_date' => '2026-10-05',
            'method' => 'cash',
            'notes' => 'استخلاص جماعي مع تنبيه ديون قديمة',
        ]);

        $response->assertStatus(201);
        $this->assertTrue((bool) $response->json('receipt.is_family_receipt'));

        // الدين القديم باقٍ كما هو بعد الاستخلاص الجماعي.
        $this->getJson($this->oldDebtUrl())->assertOk()->assertJsonPath('count', 1);
    }

    public function test_user_without_manage_payments_gets_403(): void
    {
        $role = Role::create(['name' => 'viewer_no_pay', 'display_name' => 'مطالع بلا صلاحيات']);
        $viewer = User::create([
            'role_id' => $role->id,
            'username' => 'viewer_old_debts',
            'first_name' => 'Viewer',
            'last_name' => 'User',
            'email' => 'viewer_old_debts@test.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $this->actingAs($viewer->fresh(['role.permissions']));

        $this->getJson($this->oldDebtUrl())->assertStatus(403);
    }

    public function test_query_count_stays_constant_no_n_plus_one(): void
    {
        $this->makeManualDebt($this->student1, 125);
        $this->makeManualDebt($this->student2, 300, 'registration');

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($this->oldDebtUrl())->assertOk();
        $queriesFewDebts = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(10, $queriesFewDebts);

        // ديون إضافية متعددة للتلميذين: عدد الاستعلامات يجب ألا ينمو مع كل دين.
        ManualStudentDebt::create([
            'student_id' => $this->student1->id,
            'academic_year_id' => $this->currentYear->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'club',
            'description' => 'دين ناد قديم',
            'original_amount' => 80,
            'status' => ManualStudentDebt::STATUS_PENDING,
        ]);
        ManualStudentDebt::create([
            'student_id' => $this->student2->id,
            'academic_year_id' => $this->currentYear->id,
            'original_year_label' => '2024/2025',
            'debt_type' => 'other',
            'description' => 'دين آخر',
            'original_amount' => 60,
            'status' => ManualStudentDebt::STATUS_PENDING,
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($this->oldDebtUrl())->assertOk()
            ->assertJsonPath('count', 2);
        $queriesManyDebts = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($queriesFewDebts, $queriesManyDebts);
    }

    public function test_endpoint_does_not_change_club_fees_student_fees_or_cash_transactions(): void
    {
        $this->makeManualDebt($this->student1, 125);

        $clubFeesBefore = DB::table('club_monthly_fees')->count();
        $studentFeesBefore = DB::table('student_fees')->count();
        $cashBefore = DB::table('cash_transactions')->count();

        $this->getJson($this->oldDebtUrl())->assertOk();

        $this->assertSame($clubFeesBefore, DB::table('club_monthly_fees')->count());
        $this->assertSame($studentFeesBefore, DB::table('student_fees')->count());
        $this->assertSame($cashBefore, DB::table('cash_transactions')->count());
    }

    public function test_non_existent_family_returns_404(): void
    {
        $this->getJson('/api/families/999999/old-debts')
            ->assertStatus(404);
    }

    public function test_guardian_without_students_returns_200_with_empty_debts(): void
    {
        $emptyGuardian = Guardian::create([
            'first_name' => 'فارغ',
            'last_name' => 'بلا أبناء',
            'phone' => '11223344',
            'address' => 'قفصة',
        ]);

        $this->getJson('/api/families/' . $emptyGuardian->id . '/old-debts')
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('total', 0);
    }
}
