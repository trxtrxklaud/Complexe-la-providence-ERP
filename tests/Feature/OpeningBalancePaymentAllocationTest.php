<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\Level;
use App\Models\OpeningBalance;
use App\Models\Payment;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use App\Services\CollectionService;
use App\Services\OpeningBalanceService;
use App\Services\PaymentAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * السنة المالية الجديدة: الأرصدة الافتتاحية + توزيع الدفعات.
 *
 * الفرضية المحورية: دفعة 500 د.ت تغطّي دَين 200 من سنة 2025–2026 ومعلوم
 * سبتمبر 2026–2027 بـ 300، فيسجّل النظام نقداً = 500، وقبض دَين قديم = 200،
 * ومدخول السنة الحالية = 300 فقط. لا يُعامل تحصيل الدَّين القديم كمدخول.
 */
class OpeningBalancePaymentAllocationTest extends TestCase
{
    use RefreshDatabase;

    private OpeningBalanceService $opening;

    private PaymentAllocationService $allocation;

    private CollectionService $collection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->opening = app(OpeningBalanceService::class);
        $this->allocation = app(PaymentAllocationService::class);
        $this->collection = app(CollectionService::class);
    }

    /**
     * تلميذ مُرسَّم في سنتين: 2025–2026 (مغلقة لاحقاً) و2026–2027 (نشطة).
     *
     * @return array{0:Student,1:AcademicYear,2:AcademicYear,3:Enrollment,4:Enrollment}
     */
    private function makeTwoYearSetup(): array
    {
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

        $student = Student::create([
            'student_code' => 'STU-2026',
            'first_name' => 'يوسف',
            'last_name' => 'بن محمود',
            'gender' => 'male',
            'status' => 'active',
        ]);

        $suffix = uniqid();
        $level = Level::create([
            'name' => 'السنة الأولى',
            'code' => 'L'.substr($suffix, -6),
            'order' => 1,
        ]);
        $section = Section::create([
            'level_id' => $level->id,
            'name' => 'أ',
            'code' => 'S'.substr($suffix, -6),
            'capacity' => 25,
        ]);

        $oldEnrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $old->id,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => '2025-09-01',
            'status' => 'active',
        ]);

        $newEnrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $new->id,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => '2026-09-01',
            'status' => 'active',
            'previous_enrollment_id' => $oldEnrollment->id,
        ]);

        return [$student, $old, $new, $oldEnrollment, $newEnrollment];
    }

    private function oldDebtFee(Enrollment $oldEnrollment, float $amount = 200): StudentFee
    {
        return StudentFee::create([
            'enrollment_id' => $oldEnrollment->id,
            'fee_plan_id' => null,
            'description' => 'القسط الشهري — سبتمبر 2025',
            'amount_due' => $amount,
            'due_date' => '2025-09-05',
            'status' => 'pending',
        ]);
    }

    public function test_payment_500_covers_200_old_debt_and_300_current_fee_but_revenue_is_300(): void
    {
        [$student, $old, $new, $oldEnrollment, $newEnrollment] = $this->makeTwoYearSetup();
        $user = $this->makeUser();
        $this->actingAs($user);

        // دَين 200 من سنة 2025–2026
        $oldFee = $this->oldDebtFee($oldEnrollment);

        // إقفال السنة القديمة → رصيد افتتاحي 200 في 2026–2027 يحفظ مصدر الدَّين.
        $this->opening->closeYear($old, $new, $user->id);

        $balance = OpeningBalance::firstOrFail();
        $this->assertSame(200.0, (float) $balance->amount);
        $this->assertSame($oldFee->id, $balance->source_student_fee_id);

        // القبض: 200 لسداد دَين السنة السابقة + 300 معلوم سبتمبر الجديد.
        $feeType = $this->makeFeeType('القسط الشهري', 300);
        $receipt = $this->collection->collect([
            'student_id' => $student->id,
            'enrollment_id' => $newEnrollment->id,
            'months' => ['2026-09'],
            'payment_date' => '2026-09-15',
            'method' => 'cash',
            'items' => [['fee_type_id' => $feeType->id, 'amount' => 300]],
            'prior_allocations' => [['student_fee_id' => $oldFee->id, 'amount' => 200]],
        ], $user->id);

        // الدفعة الواحدة 500
        $payment = Payment::firstOrFail();
        $this->assertSame(500.0, (float) $payment->amount);

        // نقد = 500 (قبض دَين قديم 200 + معلوم الشهر 300)
        $priorDebt = (float) CashTransaction::query()->active()->priorYearDebt()->sum('amount');
        $monthly = (float) CashTransaction::query()->active()
            ->where('category', CashTransaction::CATEGORY_MONTHLY_FEE)->sum('amount');

        $this->assertSame(200.0, $priorDebt, 'تحصيل دَين السنة السابقة = 200');
        $this->assertSame(300.0, $monthly, 'معلوم الشهر الجديد = 300');
        $this->assertSame(500.0, round($priorDebt + $monthly, 2), 'كل النقد في الصندوق = 500');

        // مدخول السنة الحالية = 300 فقط — لا يدخل قبض الدَّين القديم في المداخيل.
        $income = (float) CashTransaction::query()->active()->income()->sum('amount');
        $this->assertSame(300.0, $income, 'مدخول السنة الحالية = 300 فقط');

        // الرصيد في الخزينة (المجموع النقدي) = 500.
        $this->assertSame(500.0, (float) CashTransaction::query()->active()->sum('amount'));

        // الرسم القديم سُدّد والرصيد الافتتاحي انتهى.
        $this->assertSame('paid', $oldFee->fresh()->status);
        $this->assertSame(0.0, round($balance->fresh()->outstanding(), 2));

        // الوصل يعرض تفصيل التوزيع للمحاسب.
        $this->assertSame(200.0, (float) $receipt['prior_total']);
        $this->assertCount(2, $receipt['allocations']);
    }

    public function test_auto_allocation_suggestion_goes_oldest_outstanding_first(): void
    {
        [$student, $old, $new, $oldEnrollment, $newEnrollment] = $this->makeTwoYearSetup();

        $oldFee = $this->oldDebtFee($oldEnrollment);
        $newFee = StudentFee::create([
            'enrollment_id' => $newEnrollment->id,
            'fee_plan_id' => null,
            'description' => 'القسط الشهري — سبتمبر 2026',
            'amount_due' => 300,
            'due_date' => '2026-09-05',
            'status' => 'pending',
        ]);

        $suggestion = $this->allocation->suggest($student, 500.0);

        // الأقدم أولاً: دَين 2025–2026 ثم معلوم 2026–2027.
        $this->assertSame(200.0, (float) $suggestion['prior_year'][0]['amount']);
        $this->assertSame((int) $oldFee->id, (int) $suggestion['prior_year'][0]['student_fee_id']);
        $this->assertSame(300.0, (float) $suggestion['current_year'][0]['amount']);
        $this->assertSame((int) $newFee->id, (int) $suggestion['current_year'][0]['student_fee_id']);
        $this->assertSame(0.0, (float) $suggestion['credit']);
        $this->assertSame(500.0, (float) $suggestion['allocated']);
    }

    public function test_excess_payment_becomes_credit_not_revenue(): void
    {
        [$student, $old, $new, $oldEnrollment, $newEnrollment] = $this->makeTwoYearSetup();

        $oldFee = $this->oldDebtFee($oldEnrollment, 200);

        $suggestion = $this->allocation->suggest($student, 500.0);

        // كل المتاح 200 يُوزَّع على الدَّين القديم؛ الباقي 300 رصيد دائن.
        $this->assertSame(200.0, (float) $suggestion['prior_year'][0]['amount']);
        $this->assertSame(300.0, (float) $suggestion['credit']);
        $this->assertSame(200.0, (float) $suggestion['allocated']);
    }

    public function test_year_close_is_idempotent_and_blocks_duplicate_accrual(): void
    {
        [$student, $old, $new, $oldEnrollment] = $this->makeTwoYearSetup();
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->oldDebtFee($oldEnrollment, 200);

        $this->opening->closeYear($old, $new, $user->id);
        $this->assertDatabaseCount('opening_balances', 1);

        // الإقفال الثاني مرفوض: closed_at يمنع الازدواج.
        try {
            $this->opening->closeYear($old, $new, $user->id);
            $this->fail('كان يجب رفض إعادة إقفال السنة');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('مغلقة مسبقاً', $e->getMessage());
        }

        $this->assertDatabaseCount('opening_balances', 1);
    }

    public function test_rejects_close_active_year(): void
    {
        [$student, $old, $new] = $this->makeTwoYearSetup();
        $user = $this->makeUser();

        $this->expectException(\InvalidArgumentException::class);
        // السنة الجديدة نشطة — إقفالها مرفوض.
        $this->opening->closeYear($new, $old, $user->id);
    }

    public function test_rejects_prior_allocation_larger_than_outstanding_debt(): void
    {
        [$student, $old, $new, $oldEnrollment, $newEnrollment] = $this->makeTwoYearSetup();
        $user = $this->makeUser();
        $this->actingAs($user);

        $oldFee = $this->oldDebtFee($oldEnrollment, 200);
        $this->opening->closeYear($old, $new, $user->id);

        $feeType = $this->makeFeeType('القسط الشهري', 300);

        try {
            $this->collection->collect([
                'student_id' => $student->id,
                'enrollment_id' => $newEnrollment->id,
                'months' => ['2026-09'],
                'payment_date' => '2026-09-15',
                'method' => 'cash',
                'items' => [['fee_type_id' => $feeType->id, 'amount' => 300]],
                // 250 على دَين 200 → يجب أن يُرفض.
                'prior_allocations' => [['student_fee_id' => $oldFee->id, 'amount' => 250]],
            ], $user->id);
            $this->fail('كان يجب رفض توزيع أكبر من المتبقّي');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('يتجاوز المتبقّي', $e->getMessage());
        }

        // لا دفعة ولا أثر نقدي ولا تخصيص.
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('cash_transactions', 0);
        $this->assertSame(200.0, round($oldFee->fresh()->outstanding(), 2));
    }

    public function test_rejects_current_year_fee_inside_prior_allocations(): void
    {
        [$student, $old, $new, $oldEnrollment, $newEnrollment] = $this->makeTwoYearSetup();
        $user = $this->makeUser();
        $this->actingAs($user);

        $newFee = StudentFee::create([
            'enrollment_id' => $newEnrollment->id,
            'fee_plan_id' => null,
            'description' => 'معلوم السنة الحالية',
            'amount_due' => 300,
            'due_date' => '2026-09-05',
            'status' => 'pending',
        ]);

        $feeType = $this->makeFeeType('القسط الشهري', 300);

        $this->expectException(\InvalidArgumentException::class);
        $this->collection->collect([
            'student_id' => $student->id,
            'enrollment_id' => $newEnrollment->id,
            'months' => ['2026-09'],
            'payment_date' => '2026-09-15',
            'method' => 'cash',
            'items' => [['fee_type_id' => $feeType->id, 'amount' => 300]],
            // رسم السنة الحالية لا يمرّ عبر متخلّدات السنوات السابقة.
            'prior_allocations' => [['student_fee_id' => $newFee->id, 'amount' => 300]],
        ], $user->id);
    }

    public function test_net_income_report_distinguishes_prior_debt_from_revenue(): void
    {
        [$student, $old, $new, $oldEnrollment, $newEnrollment] = $this->makeTwoYearSetup();
        $user = $this->makeUser();
        Sanctum::actingAs($user->fresh(['role']));

        $oldFee = $this->oldDebtFee($oldEnrollment);
        $this->opening->closeYear($old, $new, $user->id);

        $feeType = $this->makeFeeType('القسط الشهري', 300);
        $this->collection->collect([
            'student_id' => $student->id,
            'enrollment_id' => $newEnrollment->id,
            'months' => ['2026-09'],
            'payment_date' => '2026-09-15',
            'method' => 'cash',
            'items' => [['fee_type_id' => $feeType->id, 'amount' => 300]],
            'prior_allocations' => [['student_fee_id' => $oldFee->id, 'amount' => 200]],
        ], $user->id);

        // تقرير الدخل الصافي: قبض الدَّين القديم بند مستقل لا مدخول.
        $this->getJson('/api/reports/net-income?date=2026-09-15')
            ->assertOk()
            ->assertJsonPath('day.income.total', 300)
            ->assertJsonPath('day.net_income', 300)
            ->assertJsonPath('day.prior_year_debt', 200)
            ->assertJsonPath('day.balance', 500);
    }

    public function test_treasury_daybook_shows_prior_debt_line(): void
    {
        [$student, $old, $new, $oldEnrollment, $newEnrollment] = $this->makeTwoYearSetup();
        $user = $this->makeUser();
        Sanctum::actingAs($user->fresh(['role']));

        $oldFee = $this->oldDebtFee($oldEnrollment);
        $this->opening->closeYear($old, $new, $user->id);

        $feeType = $this->makeFeeType('القسط الشهري', 300);
        $this->collection->collect([
            'student_id' => $student->id,
            'enrollment_id' => $newEnrollment->id,
            'months' => ['2026-09'],
            'payment_date' => '2026-09-15',
            'method' => 'cash',
            'items' => [['fee_type_id' => $feeType->id, 'amount' => 300]],
            'prior_allocations' => [['student_fee_id' => $oldFee->id, 'amount' => 200]],
        ], $user->id);

        $this->getJson('/api/reports/treasury-daybook?date=2026-09-15&date_to=2026-09-15&details=1')
            ->assertOk()
            ->assertJsonPath('summary.prior_year_debt', 200)
            ->assertJsonPath('summary.net_income', 300)
            ->assertJsonPath('summary.balance', 500)
            ->assertJsonPath('closing.balance', 500);
    }

    public function test_cancelling_payment_restores_old_debt_and_ledger(): void
    {
        [$student, $old, $new, $oldEnrollment, $newEnrollment] = $this->makeTwoYearSetup();
        $user = $this->makeUser();
        Sanctum::actingAs($user->fresh(['role']));

        $oldFee = $this->oldDebtFee($oldEnrollment);
        $this->opening->closeYear($old, $new, $user->id);

        $feeType = $this->makeFeeType('القسط الشهري', 300);
        $this->collection->collect([
            'student_id' => $student->id,
            'enrollment_id' => $newEnrollment->id,
            'months' => ['2026-09'],
            'payment_date' => '2026-09-15',
            'method' => 'cash',
            'items' => [['fee_type_id' => $feeType->id, 'amount' => 300]],
            'prior_allocations' => [['student_fee_id' => $oldFee->id, 'amount' => 200]],
        ], $user->id);

        $payment = Payment::firstOrFail();
        $this->assertSame(2, CashTransaction::active()->count());

        // إلغاء موثّق بدل الحذف: تُعاد الأمور لما كانت عليه والتدقيق محفوظ.
        $this->postJson("/api/payments/{$payment->id}/cancel", ['reason' => 'خطأ في المبلغ'])
            ->assertOk();

        $this->assertSame('pending', $oldFee->fresh()->status);
        $this->assertSame(200.0, round(OpeningBalance::first()->outstanding(), 2));
        // كل أسطر الدفتر أُلغيَت بلا حذف.
        $this->assertSame(0, CashTransaction::active()->count());
        $this->assertSame(2, CashTransaction::whereNotNull('cancelled_at')->count());
    }

    public function test_close_year_endpoint_and_opening_balances_endpoint(): void
    {
        [$student, $old, $new, $oldEnrollment] = $this->makeTwoYearSetup();
        $user = $this->makeUser();
        Sanctum::actingAs($user->fresh(['role']));

        $oldFee = $this->oldDebtFee($oldEnrollment);

        // إقفال السنة عبر الواجهة.
        $this->postJson("/api/academic-years/{$old->id}/close", ['target_year_id' => $new->id])
            ->assertOk()
            ->assertJsonPath('result.created', 1);

        // عرض الرصيد الافتتاحي في شاشة الاستخلاص.
        $this->getJson("/api/collection/students/{$student->id}/opening-balances")
            ->assertOk()
            ->assertJsonPath('summary.outstanding', 200)
            ->assertJsonPath('items.0.student_fee_id', (int) $oldFee->id);

        // معاينة التوزيع: 500 د.ت → دَين قديم أولاً.
        $this->getJson("/api/collection/students/{$student->id}/allocation-preview?amount=500")
            ->assertOk()
            ->assertJsonPath('prior_year.0.amount', 200)
            ->assertJsonPath('credit', 300);
    }

    public function test_collection_with_opening_balance_id_stores_explicit_fk(): void
    {
        [$student, $old, $new, $oldEnrollment, $newEnrollment] = $this->makeTwoYearSetup();
        $user = $this->makeUser();
        Sanctum::actingAs($user->fresh(['role']));

        $oldFee = $this->oldDebtFee($oldEnrollment);
        $this->opening->closeYear($old, $new, $user->id);
        $ob = OpeningBalance::firstOrFail();

        $this->collection->collect([
            'student_id' => $student->id,
            'enrollment_id' => $newEnrollment->id,
            'payment_date' => '2026-09-15',
            'method' => 'cash',
            'prior_allocations' => [['opening_balance_id' => $ob->id, 'amount' => 150]],
        ], $user->id);

        $allocation = \App\Models\PaymentAllocation::firstOrFail();
        $this->assertSame($ob->id, $allocation->opening_balance_id);
        $this->assertNull($allocation->manual_student_debt_id);
        $this->assertSame($oldFee->id, $allocation->student_fee_id);
        $this->assertSame(150.0, (float) $allocation->amount_allocated);

        $this->assertSame(150.0, $ob->collected());
        $this->assertSame(50.0, $ob->outstanding());
    }

    public function test_cannot_exceed_opening_balance_outstanding(): void
    {
        [$student, $old, $new, $oldEnrollment, $newEnrollment] = $this->makeTwoYearSetup();
        $user = $this->makeUser();
        Sanctum::actingAs($user->fresh(['role']));

        $oldFee = $this->oldDebtFee($oldEnrollment); // 200 د.ت
        $this->opening->closeYear($old, $new, $user->id);
        $ob = OpeningBalance::firstOrFail();

        // محاولة سداد 250 د.ت لرصيد متبقّ منه 200 د.ت
        $this->expectException(\InvalidArgumentException::class);
        $this->collection->collect([
            'student_id' => $student->id,
            'enrollment_id' => $newEnrollment->id,
            'payment_date' => '2026-09-15',
            'method' => 'cash',
            'prior_allocations' => [['opening_balance_id' => $ob->id, 'amount' => 250]],
        ], $user->id);
    }
}
