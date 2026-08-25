<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\Level;
use App\Models\ManualStudentDebt;
use App\Models\Payment;
use App\Models\Section;
use App\Models\Student;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * جسر الدَّين اليدوي تحت تسجيل السنة الحالية (الإدخال الجماعي).
 *
 * القاعدة: التخصيص الذي يحمل manual_student_debt_id يصنَّف متخلّدات
 * (prior_year_debt/in) بغضّ النظر عن سنة تسجيل رسم الجسر، ولا يدخل
 * في current_year_income أو net_income، ولا يرتبط بشهر دراسي.
 * التخصيص بلا هدف صريح على رسم السنة الحالية يبقى مرفوضاً كما كان.
 */
class OldDebtBridgeCurrentYearTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;
    private Level $level;
    private Section $section;
    private Student $student;
    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->makeUser('admin');
        $admin->update(['is_active' => true]);
        Sanctum::actingAs($admin);

        $this->year = $this->makeAcademicYear('2026-2027');
        $this->level = Level::create(['name' => 'السنة الأولى', 'code' => 'L-'.uniqid(), 'order' => 1]);
        $this->section = Section::create(['level_id' => $this->level->id, 'name' => 'أ', 'code' => 'S-'.uniqid(), 'capacity' => 30]);

        $this->student = Student::create([
            'student_code' => 'STU-BRG-'.uniqid(),
            'first_name' => 'محمد',
            'last_name' => 'الجسري',
            'gender' => 'male',
            'status' => 'active',
        ]);
        $this->enrollment = Enrollment::create([
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'level_id' => $this->level->id,
            'section_id' => $this->section->id,
            'status' => 'active',
            'enrollment_date' => $this->year->start_date,
        ]);
    }

    /** دَين عبر الإدخال الجماعي — جسره تحت تسجيل السنة الحالية عمداً. */
    private function createBulkDebt(float $amount = 1000, ?Student $forStudent = null): ManualStudentDebt
    {
        $target = $forStudent ?? $this->student;
        $this->postJson('/api/manual-debts/bulk', [
            'academic_year_id' => $this->year->id,
            'original_year_label' => '2025/2026',
            'items' => [
                ['student_id' => $target->id, 'debt_type' => 'tuition', 'amount' => $amount],
            ],
        ])->assertCreated();

        return ManualStudentDebt::where('student_id', $target->id)->firstOrFail();
    }

    public function test_current_year_bridge_collect_succeeds(): void
    {
        $debt = $this->createBulkDebt(1000);

        $res = $this->postJson("/api/manual-debts/{$debt->id}/collect", [
            'amount' => 300,
            'payment_date' => '2025-10-01',
            'method' => 'cash',
        ]);
        $res->assertCreated();

        $debt->refresh();
        $this->assertEqualsWithDelta(700, $debt->outstanding(), 0.001);
        $this->assertSame(ManualStudentDebt::STATUS_PARTIAL, $debt->status);

        // الجسر فعلاً تحت تسجيل السنة الحالية — حالة الاختبار صحيحة.
        $this->assertSame(
            $this->year->id,
            $debt->sourceStudentFee->enrollment->academic_year_id
        );
    }

    public function test_allocation_stores_explicit_manual_student_debt_target(): void
    {
        $debt = $this->createBulkDebt(1000);

        $this->postJson("/api/manual-debts/{$debt->id}/collect", [
            'amount' => 300,
            'payment_date' => '2025-10-01',
            'method' => 'cash',
        ])->assertCreated();

        $this->assertDatabaseHas('payment_allocations', [
            'manual_student_debt_id' => $debt->id,
            'student_fee_id' => $debt->source_student_fee_id,
            'opening_balance_id' => null,
            'amount_allocated' => 300,
        ]);
    }

    public function test_cash_transaction_classified_prior_year_debt_in(): void
    {
        $debt = $this->createBulkDebt(1000);

        $this->postJson("/api/manual-debts/{$debt->id}/collect", [
            'amount' => 300,
            'payment_date' => '2025-10-01',
            'method' => 'cash',
        ])->assertCreated();

        $this->assertDatabaseHas('cash_transactions', [
            'category' => CashTransaction::CATEGORY_PRIOR_YEAR_DEBT,
            'direction' => CashTransaction::DIRECTION_IN,
            'amount' => 300,
        ]);
        $this->assertDatabaseMissing('cash_transactions', [
            'category' => CashTransaction::CATEGORY_MONTHLY_FEE,
        ]);
    }

    public function test_not_in_current_year_income(): void
    {
        $debt = $this->createBulkDebt(1000);

        $this->postJson("/api/manual-debts/{$debt->id}/collect", [
            'amount' => 300,
            'payment_date' => '2025-10-01',
            'method' => 'cash',
        ])->assertCreated();

        /** @var DashboardService $svc */
        $svc = app(DashboardService::class);
        $ref = new \ReflectionMethod($svc, 'cashFigures');
        $ref->setAccessible(true);
        $figures = $ref->invoke($svc, null, now()->toDateString());

        $this->assertEqualsWithDelta(300, $figures['old_debt_collections'], 0.001);
        $this->assertEqualsWithDelta(300, $figures['cash_in'], 0.001);
        $this->assertEqualsWithDelta(0, $figures['current_year_income'], 0.001);
    }

    public function test_not_in_net_income(): void
    {
        $debt = $this->createBulkDebt(1000);

        $this->postJson("/api/manual-debts/{$debt->id}/collect", [
            'amount' => 300,
            'payment_date' => '2025-10-01',
            'method' => 'cash',
        ])->assertCreated();

        /** @var DashboardService $svc */
        $svc = app(DashboardService::class);
        $ref = new \ReflectionMethod($svc, 'cashFigures');
        $ref->setAccessible(true);
        $figures = $ref->invoke($svc, null, now()->toDateString());

        $this->assertEqualsWithDelta(0, $figures['net_income'], 0.001);
        $this->assertEqualsWithDelta(300, $figures['balance'], 0.001);
    }

    public function test_plain_current_fee_without_explicit_target_still_rejected(): void
    {
        // رسم عادي للسنة الحالية (بلا هدف دين صريح) عبر prior_allocations يبقى مرفوضاً.
        $feeType = $this->makeFeeType('القسط الشهري', 400);
        $this->postJson('/api/payments/collect', [
            'student_id' => $this->student->id,
            'enrollment_id' => $this->enrollment->id,
            'months' => ['2025-09'],
            'items' => [['fee_type_id' => $feeType->id, 'amount' => 400]],
            'payment_date' => '2025-09-20',
            'method' => 'cash',
        ])->assertCreated();

        $fee = \App\Models\StudentFee::where('enrollment_id', $this->enrollment->id)
            ->where('fee_type_id', $feeType->id)
            ->firstOrFail();

        $this->postJson('/api/payments/collect', [
            'student_id' => $this->student->id,
            'enrollment_id' => $this->enrollment->id,
            'payment_date' => '2025-10-01',
            'method' => 'cash',
            'prior_allocations' => [['student_fee_id' => $fee->id, 'amount' => 50]],
        ])->assertStatus(422);
    }

    public function test_rejects_debt_of_another_student(): void
    {
        $other = Student::create([
            'student_code' => 'STU-OTH-'.uniqid(),
            'first_name' => 'آخر',
            'last_name' => 'التلميذ',
            'gender' => 'male',
            'status' => 'active',
        ]);
        Enrollment::create([
            'student_id' => $other->id,
            'academic_year_id' => $this->year->id,
            'level_id' => $this->level->id,
            'section_id' => $this->section->id,
            'status' => 'active',
            'enrollment_date' => $this->year->start_date,
        ]);
        $debt = $this->createBulkDebt(1000, $other);

        // دَين التلميذ الآخر لا يمكن تخصيصه عبر تسجيل الطالب الأول
        // (المسار العام: prior_allocations مع تسجيل الطالب الأول) → رفض.
        $this->postJson('/api/payments/collect', [
            'student_id' => $this->student->id,
            'enrollment_id' => $this->enrollment->id,
            'payment_date' => '2025-10-01',
            'method' => 'cash',
            'prior_allocations' => [['manual_student_debt_id' => $debt->id, 'amount' => 100]],
        ])->assertStatus(422);
    }

    public function test_rejects_cancelled_debt(): void
    {
        $debt = $this->createBulkDebt(1000);

        $this->postJson("/api/manual-debts/{$debt->id}/cancel", [
            'reason' => 'إدخال خاطئ',
        ])->assertOk();

        $this->postJson("/api/manual-debts/{$debt->id}/collect", [
            'amount' => 100,
            'payment_date' => '2025-10-01',
            'method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_rejects_over_outstanding(): void
    {
        $debt = $this->createBulkDebt(1000);

        $this->postJson("/api/manual-debts/{$debt->id}/collect", [
            'amount' => 1001,
            'payment_date' => '2025-10-01',
            'method' => 'cash',
        ])->assertStatus(422);

        $debt->refresh();
        $this->assertEqualsWithDelta(1000, $debt->outstanding(), 0.001);
    }

    public function test_rejects_zero_and_negative(): void
    {
        $debt = $this->createBulkDebt(1000);

        $this->postJson("/api/manual-debts/{$debt->id}/collect", [
            'amount' => 0,
            'payment_date' => '2025-10-01',
            'method' => 'cash',
        ])->assertStatus(422);

        $this->postJson("/api/manual-debts/{$debt->id}/collect", [
            'amount' => -100,
            'payment_date' => '2025-10-01',
            'method' => 'cash',
        ])->assertStatus(422);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_full_collection_marks_paid(): void
    {
        $debt = $this->createBulkDebt(1000);

        $this->postJson("/api/manual-debts/{$debt->id}/collect", [
            'amount' => 1000,
            'payment_date' => '2025-10-01',
            'method' => 'cash',
        ])->assertCreated();

        $debt->refresh();
        $this->assertEqualsWithDelta(0, $debt->outstanding(), 0.001);
        $this->assertSame(ManualStudentDebt::STATUS_PAID, $debt->status);
    }

    public function test_cancel_reverses_cash_and_restores_outstanding(): void
    {
        $debt = $this->createBulkDebt(1000);

        $receipt = $this->postJson("/api/manual-debts/{$debt->id}/collect", [
            'amount' => 300,
            'payment_date' => '2025-10-01',
            'method' => 'cash',
        ])->json('receipt');

        $this->assertEqualsWithDelta(
            300,
            (float) CashTransaction::whereNull('cancelled_at')->sum('amount'),
            0.001
        );

        $this->postJson('/api/payments/'.$receipt['payment_id'].'/cancel', [
            'reason' => 'إلغاء للاختبار',
        ])->assertOk();

        $this->assertEqualsWithDelta(
            0,
            (float) CashTransaction::whereNull('cancelled_at')->sum('amount'),
            0.001
        );

        $debt->refresh();
        $this->assertEqualsWithDelta(1000, $debt->outstanding(), 0.001);
        $this->assertSame(ManualStudentDebt::STATUS_PENDING, $debt->status);

        // السجل محفوظ (ملغى لا محذوف).
        $this->assertDatabaseHas('payments', ['id' => $receipt['payment_id']]);
    }

    public function test_no_academic_months_linkage(): void
    {
        $debt = $this->createBulkDebt(1000);

        $this->postJson("/api/manual-debts/{$debt->id}/collect", [
            'amount' => 300,
            'payment_date' => '2025-10-01',
            'method' => 'cash',
        ])->assertCreated();

        $months = Payment::query()->latest('id')->value('months');
        $this->assertTrue(
            $months === null || $months === [] || $months === '[]',
            'تحصيل الدين القديم يجب ألا يرتبط بشهر دراسي.'
        );
    }
}
