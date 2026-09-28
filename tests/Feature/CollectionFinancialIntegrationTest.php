<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\Level;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use App\Services\CollectionService;
use App\Services\LedgerService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * اختبارات التكامل المالي المحاسبية الصارمة (Financial Invariants & Ledger Integrity).
 */
class CollectionFinancialIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private CollectionService $collectionService;
    private LedgerService $ledgerService;
    private PaymentService $paymentService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collectionService = app(CollectionService::class);
        $this->ledgerService = app(LedgerService::class);
        $this->paymentService = app(PaymentService::class);
    }

    private function setupFinancialEnvironment(): array
    {
        $year = $this->makeAcademicYear();
        $suffix = uniqid();

        $level = Level::create([
            'name' => 'السنة الأولى',
            'code' => 'L1',
            'order' => 1,
        ]);

        $section = Section::create([
            'level_id' => $level->id,
            'name' => 'فوج أ',
            'code' => 'SEC_' . $suffix,
            'capacity' => 20,
        ]);

        $student = Student::create([
            'student_code' => 'STU_' . $suffix,
            'first_name' => 'سارة',
            'last_name' => 'العياري',
            'gender' => 'female',
            'status' => 'active',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => '2025-09-01',
            'status' => 'active',
        ]);

        $tuitionType = FeeType::create([
            'name_ar' => 'القسط الشهري',
            'code' => 'TUITION',
            'price' => 100.0,
            'ledger_category' => 'monthly_fee',
            'is_active' => true,
        ]);

        $feeCategory = FeeCategory::firstOrCreate(
            ['code' => 'TUITION'],
            ['name' => 'Frais Scolaires', 'is_recurring' => true]
        );

        FeePlan::create([
            'academic_year_id' => $year->id,
            'level_id' => $level->id,
            'fee_category_id' => $feeCategory->id,
            'name' => 'القسط الشهري',
            'amount' => 100.0,
            'frequency' => 'monthly',
        ]);

        $user = $this->makeUser('admin');
        $user->update(['is_active' => true]);

        return compact('year', 'level', 'section', 'student', 'enrollment', 'tuitionType', 'user');
    }

    public function test_treasury_balance_increases_by_exact_payment_amount(): void
    {
        $data = $this->setupFinancialEnvironment();
        $user = $data['user'];
        Sanctum::actingAs($user);

        $treasuryBefore = (float) CashTransaction::whereNull('cancelled_at')->sum('amount');

        $receipt = $this->collectionService->collect([
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'months' => ['2025-09'],
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'items' => [
                ['fee_type_id' => $data['tuitionType']->id, 'amount' => 100.0],
            ],
        ], $user->id);

        $collectedAmount = (float) $receipt['total'];
        $treasuryAfter = (float) CashTransaction::whereNull('cancelled_at')->sum('amount');

        // المعادلة المحاسبية الحاكمة: رصيد الخزينة بعد = رصيد الخزينة قبل + المقبوض
        $this->assertSame(round($treasuryBefore + $collectedAmount, 2), round($treasuryAfter, 2));
    }

    public function test_first_half_reduces_outstanding_by_exact_collected_amount(): void
    {
        $data = $this->setupFinancialEnvironment();
        $user = $data['user'];

        $fee = StudentFee::create([
            'enrollment_id' => $data['enrollment']->id,
            'fee_type_id' => $data['tuitionType']->id,
            'description' => 'القسط الشهري — سبتمبر 2025',
            'amount_due' => 100.0,
            'due_date' => '2025-09-01',
            'status' => 'pending',
        ]);

        $outstandingBefore = $fee->outstanding(); // 100.0
        $collectedHalf = 50.0;

        $payment = Payment::create([
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'amount' => $collectedHalf,
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'months' => ['2025-09'],
            'created_by' => $user->id,
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'student_fee_id' => $fee->id,
            'amount_allocated' => $collectedHalf,
        ]);
        $this->paymentService->recalculateStudentFeeStatus($fee->id);

        $outstandingAfter = $fee->fresh()->outstanding();

        // المعادلة المحاسبية: outstanding بعد = outstanding قبل - المقبوض
        $this->assertSame(round($outstandingBefore - $collectedHalf, 2), round($outstandingAfter, 2));
        $this->assertSame(50.0, $outstandingAfter);
    }

    public function test_second_half_settlement_reduces_outstanding_to_exact_zero(): void
    {
        $data = $this->setupFinancialEnvironment();
        $user = $data['user'];

        $fee = StudentFee::create([
            'enrollment_id' => $data['enrollment']->id,
            'fee_type_id' => $data['tuitionType']->id,
            'description' => 'القسط الشهري — سبتمبر 2025',
            'amount_due' => 100.0,
            'due_date' => '2025-09-01',
            'status' => 'pending',
        ]);

        // جزء 1: 50 د.ت
        $p1 = Payment::create([
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'amount' => 50.0,
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'months' => ['2025-09'],
            'created_by' => $user->id,
        ]);
        PaymentAllocation::create(['payment_id' => $p1->id, 'student_fee_id' => $fee->id, 'amount_allocated' => 50.0]);

        // جزء 2: 50 د.ت
        $p2 = Payment::create([
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'amount' => 50.0,
            'payment_date' => '2025-09-20',
            'method' => 'cash',
            'months' => ['2025-09'],
            'created_by' => $user->id,
        ]);
        PaymentAllocation::create(['payment_id' => $p2->id, 'student_fee_id' => $fee->id, 'amount_allocated' => 50.0]);

        $this->paymentService->recalculateStudentFeeStatus($fee->id);

        $fee->refresh();
        $this->assertSame('paid', $fee->status);
        $this->assertSame(0.0, $fee->outstanding());
    }

    public function test_payment_and_allocation_and_cash_transaction_are_in_one_to_one_correspondence(): void
    {
        $data = $this->setupFinancialEnvironment();
        $user = $data['user'];
        Sanctum::actingAs($user);

        $this->collectionService->collect([
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'months' => ['2025-09'],
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'items' => [
                ['fee_type_id' => $data['tuitionType']->id, 'amount' => 100.0],
            ],
        ], $user->id);

        $payment = Payment::first();
        $this->assertNotNull($payment);

        // تخصيصات الدفعة
        $allocatedSum = (float) PaymentAllocation::where('payment_id', $payment->id)->sum('amount_allocated');
        $this->assertSame((float) $payment->amount, $allocatedSum);

        // قيد الخزينة المقابل
        $cashTx = CashTransaction::where('source_type', Payment::class)
            ->where('source_id', $payment->id)
            ->first();

        $this->assertNotNull($cashTx);
        $this->assertSame((float) $payment->amount, (float) $cashTx->amount);
        $this->assertSame(CashTransaction::CATEGORY_MONTHLY_FEE, $cashTx->category);

        // لا يوجد قيد مكرر على (source_type, source_id, category)
        $txCount = CashTransaction::where('source_type', Payment::class)
            ->where('source_id', $payment->id)
            ->where('category', CashTransaction::CATEGORY_MONTHLY_FEE)
            ->count();
        $this->assertSame(1, $txCount);
    }

    public function test_cancelling_payment_reduces_treasury_by_exact_payment_amount(): void
    {
        $data = $this->setupFinancialEnvironment();
        $user = $data['user'];
        Sanctum::actingAs($user);

        $this->collectionService->collect([
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'months' => ['2025-09'],
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'items' => [
                ['fee_type_id' => $data['tuitionType']->id, 'amount' => 100.0],
            ],
        ], $user->id);

        $payment = Payment::first();
        $treasuryBeforeCancel = (float) CashTransaction::whereNull('cancelled_at')->sum('amount');

        // إلغاء الدفعة
        $this->ledgerService->cancelFor($payment, $user->id, 'إلغاء دفعة للاختبار');

        $treasuryAfterCancel = (float) CashTransaction::whereNull('cancelled_at')->sum('amount');

        // المعادلة المحاسبية: رصيد بعد الإلغاء = رصيد قبل الإلغاء - مبلغ الدفعة
        $this->assertSame(round($treasuryBeforeCancel - (float) $payment->amount, 2), round($treasuryAfterCancel, 2));
    }

    public function test_collection_does_not_mutate_prior_debts_or_advances_categories(): void
    {
        $data = $this->setupFinancialEnvironment();
        $user = $data['user'];
        Sanctum::actingAs($user);

        // محاكاة قيد سلفة موظف وقيد دين سابق في الدفتر
        CashTransaction::create([
            'transaction_date' => '2025-09-01',
            'amount' => 500.0,
            'direction' => CashTransaction::DIRECTION_OUT,
            'category' => CashTransaction::CATEGORY_EMPLOYEE_ADVANCE,
            'description' => 'سلفة موظف سابقة',
            'source_type' => 'App\\Models\\EmployeeAdvance',
            'source_id' => 999,
            'created_by' => $user->id,
        ]);

        CashTransaction::create([
            'transaction_date' => '2025-09-02',
            'amount' => 300.0,
            'direction' => CashTransaction::DIRECTION_IN,
            'category' => CashTransaction::CATEGORY_PRIOR_YEAR_DEBT,
            'description' => 'تحصيل دين سابق',
            'source_type' => 'App\\Models\\Payment',
            'source_id' => 998,
            'created_by' => $user->id,
        ]);

        $advancesCountBefore = CashTransaction::where('category', CashTransaction::CATEGORY_EMPLOYEE_ADVANCE)->count();
        $priorDebtsCountBefore = CashTransaction::where('category', CashTransaction::CATEGORY_PRIOR_YEAR_DEBT)->count();

        // تنفيذ استخلاص قسط شهري عادي
        $this->collectionService->collect([
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'months' => ['2025-09'],
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'items' => [
                ['fee_type_id' => $data['tuitionType']->id, 'amount' => 100.0],
            ],
        ], $user->id);

        $advancesCountAfter = CashTransaction::where('category', CashTransaction::CATEGORY_EMPLOYEE_ADVANCE)->count();
        $priorDebtsCountAfter = CashTransaction::where('category', CashTransaction::CATEGORY_PRIOR_YEAR_DEBT)->count();

        // التأكد من عدم تأثر تصنيفات السلف أو الديون القديمة
        $this->assertSame($advancesCountBefore, $advancesCountAfter);
        $this->assertSame($priorDebtsCountBefore, $priorDebtsCountAfter);
    }
}
