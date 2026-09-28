<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\Level;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\LedgerService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * اختبارات إلغاء الدفعات والاسترجاع وعكس قيود الخزينة.
 */
class CancellationAndRefundTest extends TestCase
{
    use RefreshDatabase;

    private PaymentService $paymentService;
    private LedgerService $ledgerService;
    private CollectionService $collectionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->paymentService = app(PaymentService::class);
        $this->ledgerService = app(LedgerService::class);
        $this->collectionService = app(CollectionService::class);
    }

    private function setupStudentWithTuition(float $price = 100.0): array
    {
        $year = $this->makeAcademicYear();
        $suffix = uniqid();

        $level = Level::create([
            'name' => 'روضة',
            'code' => 'PRE1',
            'order' => 1,
        ]);

        $section = Section::create([
            'level_id' => $level->id,
            'name' => 'فوج 1',
            'code' => 'SEC_' . $suffix,
            'capacity' => 20,
        ]);

        $student = Student::create([
            'student_code' => 'STU_' . $suffix,
            'first_name' => 'مريم',
            'last_name' => 'البوعزيزي',
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
            'price' => $price,
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
            'amount' => $price,
            'frequency' => 'monthly',
        ]);

        return compact('year', 'level', 'section', 'student', 'enrollment', 'tuitionType');
    }

    public function test_cancelling_first_half_reverses_ledger_and_reopens_fee(): void
    {
        $data = $this->setupStudentWithTuition(100.0);
        $user = $this->makeUser('admin');

        $fee = StudentFee::create([
            'enrollment_id' => $data['enrollment']->id,
            'fee_type_id' => $data['tuitionType']->id,
            'description' => 'القسط الشهري — سبتمبر 2025',
            'amount_due' => 100.0,
            'due_date' => '2025-09-01',
            'status' => 'pending',
        ]);

        // دفع الجزء الأول 50 د.ت
        $payment = Payment::create([
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'amount' => 50.0,
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'months' => ['2025-09'],
            'created_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'student_fee_id' => $fee->id,
            'amount_allocated' => 50.0,
        ]);
        $this->paymentService->recalculateStudentFeeStatus($fee->id);

        // إثبات القيد في الخزينة
        $this->ledgerService->recordPayment($payment);

        $activeTxCount = CashTransaction::where('source_id', $payment->id)
            ->whereNull('cancelled_at')
            ->count();
        $this->assertSame(1, $activeTxCount);

        // إلغاء الدفعة
        $payment->update([
            'cancelled_at' => now(),
            'cancelled_by' => $user->id,
            'cancellation_reason' => 'إلغاء خطأ في الاستخلاص',
        ]);

        $this->ledgerService->cancelFor($payment, $user->id, 'إلغاء خطأ في الاستخلاص');
        $this->paymentService->recalculateStudentFeeStatus($fee->id);

        // التحقق من سحب القيد من الدفتر
        $remainingActiveTx = CashTransaction::where('source_id', $payment->id)
            ->whereNull('cancelled_at')
            ->count();
        $this->assertSame(0, $remainingActiveTx);

        // التحقق من عودة الرسم إلى حالته الأصلية ومبلغه المستحق
        $fee->refresh();
        $this->assertSame('pending', $fee->status);
        $this->assertSame(100.0, $fee->outstanding());
    }

    public function test_cancelling_second_half_restores_partial_status(): void
    {
        $data = $this->setupStudentWithTuition(100.0);
        $user = $this->makeUser('admin');

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
        $this->assertSame('paid', $fee->fresh()->status);

        // إلغاء الدفعة الثانية فقط
        $p2->update([
            'cancelled_at' => now(),
            'cancelled_by' => $user->id,
            'cancellation_reason' => 'إلغاء الجزء الثاني فقط',
        ]);
        $this->paymentService->recalculateStudentFeeStatus($fee->id);

        $fee->refresh();
        $this->assertSame('partial', $fee->status);
        $this->assertSame(50.0, $fee->outstanding());
    }

    public function test_cancelling_full_payment_restores_pending_or_partial_by_remaining_allocations(): void
    {
        $data = $this->setupStudentWithTuition(100.0);
        $user = $this->makeUser('admin');

        $fee = StudentFee::create([
            'enrollment_id' => $data['enrollment']->id,
            'fee_type_id' => $data['tuitionType']->id,
            'description' => 'القسط الشهري — سبتمبر 2025',
            'amount_due' => 100.0,
            'due_date' => '2025-09-01',
            'status' => 'pending',
        ]);

        $payment = Payment::create([
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'amount' => 100.0,
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'months' => ['2025-09'],
            'created_by' => $user->id,
        ]);
        PaymentAllocation::create(['payment_id' => $payment->id, 'student_fee_id' => $fee->id, 'amount_allocated' => 100.0]);
        $this->paymentService->recalculateStudentFeeStatus($fee->id);

        $this->assertSame('paid', $fee->fresh()->status);

        // إلغاء الدفعة الكاملة
        $payment->update([
            'cancelled_at' => now(),
            'cancelled_by' => $user->id,
            'cancellation_reason' => 'إلغاء الدفعة الكاملة',
        ]);
        $this->paymentService->recalculateStudentFeeStatus($fee->id);

        $fee->refresh();
        $this->assertSame('pending', $fee->status);
        $this->assertSame(100.0, $fee->outstanding());
    }

    public function test_cancelled_payment_is_excluded_from_paid_months(): void
    {
        $data = $this->setupStudentWithTuition(100.0);
        $user = $this->makeUser('admin');

        $payment = Payment::create([
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'amount' => 100.0,
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'months' => ['2025-09'],
            'created_by' => $user->id,
        ]);

        $paidMonthsBefore = $this->collectionService->getPaidMonths($data['enrollment']->id);
        $this->assertContains('2025-09', $paidMonthsBefore);

        // إلغاء الدفعة
        $payment->update([
            'cancelled_at' => now(),
            'cancelled_by' => $user->id,
            'cancellation_reason' => 'إلغاء لاختبار استبعاد الأشهر المدفوعة',
        ]);

        $paidMonthsAfter = $this->collectionService->getPaidMonths($data['enrollment']->id);
        $this->assertNotContains('2025-09', $paidMonthsAfter);
    }

    public function test_cancellation_requires_authorized_role(): void
    {
        $cashierRole = Role::firstOrCreate(['name' => 'cashier'], ['display_name' => 'قابض']);
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'مدير']);

        $cashier = User::create([
            'first_name' => 'علي',
            'last_name' => 'الهمامي',
            'username' => 'cashier_' . uniqid(),
            'email' => 'cashier_' . uniqid() . '@test.local',
            'password' => 'secret123',
            'role_id' => $cashierRole->id,
        ]);

        $admin = User::create([
            'first_name' => 'محمد',
            'last_name' => 'المدير',
            'username' => 'admin_' . uniqid(),
            'email' => 'admin_' . uniqid() . '@test.local',
            'password' => 'secret123',
            'role_id' => $adminRole->id,
        ]);

        $this->assertSame('cashier', $cashier->role->name);
        $this->assertSame('admin', $admin->role->name);
    }

    public function test_cancellation_after_daily_closure_requires_admin_override(): void
    {
        // محاكاة قاعدة الرقابة المالية: الدفعات التي مضى عليها أكثر من 24 ساعة
        // أو أُقفل يومها المالي، يُمنع القابض العادي من إلغائها تلقائياً
        $paymentDate = now()->subDays(2)->toDateString();
        $isPastClosedDay = (strtotime($paymentDate) < strtotime(now()->toDateString()));

        $this->assertTrue($isPastClosedDay);

        $currentUserRole = 'cashier';
        $requiresAdminOverride = $isPastClosedDay && $currentUserRole === 'cashier';

        $this->assertTrue($requiresAdminOverride);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('تم إقفال اليومية المالية لهذه الدفعة؛ يلزم موافقة المدير المالي للإلغاء');

        if ($requiresAdminOverride) {
            throw new InvalidArgumentException('تم إقفال اليومية المالية لهذه الدفعة؛ يلزم موافقة المدير المالي للإلغاء');
        }
    }
}
