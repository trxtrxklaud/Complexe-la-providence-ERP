<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\FeeWaiver;
use App\Models\Level;
use App\Models\MonthlyDiscount;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use App\Services\AuditService;
use App\Services\CollectionService;
use App\Services\FeeWaiverService;
use App\Services\MonthlyDiscountService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * اختبارات دورة حياة التخفيضات والإعفاءات والتنازلات.
 */
class DiscountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private MonthlyDiscountService $discountService;
    private FeeWaiverService $waiverService;
    private PaymentService $paymentService;
    private CollectionService $collectionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->discountService = app(MonthlyDiscountService::class);
        $this->waiverService = app(FeeWaiverService::class);
        $this->paymentService = app(PaymentService::class);
        $this->collectionService = app(CollectionService::class);
    }

    private function setupStudentAndPlan(float $monthlyGross = 100.0): array
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
            'name' => 'فوج 1',
            'code' => 'SEC_' . $suffix,
            'capacity' => 20,
        ]);

        $student = Student::create([
            'student_code' => 'STU_' . $suffix,
            'first_name' => 'إلياس',
            'last_name' => 'الزيدي',
            'gender' => 'male',
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
            'price' => $monthlyGross,
            'ledger_category' => 'monthly_fee',
            'is_active' => true,
        ]);

        $feeCategory = FeeCategory::firstOrCreate(
            ['code' => 'TUITION'],
            ['name' => 'Frais Scolaires', 'is_recurring' => true]
        );

        $feePlan = FeePlan::create([
            'academic_year_id' => $year->id,
            'level_id' => $level->id,
            'fee_category_id' => $feeCategory->id,
            'name' => 'القسط الشهري',
            'amount' => $monthlyGross,
            'frequency' => 'monthly',
        ]);

        return compact('year', 'level', 'section', 'student', 'enrollment', 'tuitionType', 'feePlan');
    }

    public function test_discount_before_payment_changes_server_preview(): void
    {
        $data = $this->setupStudentAndPlan(100.0);
        $user = $this->makeUser('admin');

        // معاينة قبل الخصم: المستحق 100 د.ت
        $previewBefore = $this->collectionService->preview($data['enrollment']->id, ['2025-09'], $data['tuitionType']->id);
        $this->assertSame(100.0, (float) $previewBefore['remaining_amount']);

        // تسجيل تخفيض شهري عادي بنسبة 20% (20 د.ت)
        $this->discountService->createDiscount(
            $data['enrollment']->id,
            MonthlyDiscount::TYPE_NORMAL_MONTHLY,
            20.0,
            'تخفيض إخوة',
            'ملاحظة إدارية',
            $user->id,
            '2025-09',
            '2026-06'
        );

        // معاينة بعد الخصم: ينخفض المستحق إلى 80 د.ت
        $previewAfter = $this->collectionService->preview($data['enrollment']->id, ['2025-09'], $data['tuitionType']->id);
        $this->assertSame(80.0, (float) $previewAfter['remaining_amount']);
        $this->assertSame(20.0, (float) $previewAfter['discount_amount']);
    }

    public function test_discount_cannot_exceed_fee_amount(): void
    {
        $data = $this->setupStudentAndPlan(100.0);
        $user = $this->makeUser('admin');

        // محاولة إدخال تخفيض عادي أكبر من 20% (مثلاً 25 د.ت)
        $this->expectException(InvalidArgumentException::class);
        $this->discountService->createDiscount(
            $data['enrollment']->id,
            MonthlyDiscount::TYPE_NORMAL_MONTHLY,
            25.0,
            'تخفيض يتجاوز السقف',
            null,
            $user->id
        );
    }

    public function test_discount_and_waiver_cannot_exceed_amount_due(): void
    {
        $data = $this->setupStudentAndPlan(100.0);
        $user = $this->makeUser('admin');

        $fee = StudentFee::create([
            'enrollment_id' => $data['enrollment']->id,
            'fee_type_id' => $data['tuitionType']->id,
            'description' => 'القسط الشهري — سبتمبر 2025',
            'amount_due' => 80.0, // بعد تخفيض 20 د.ت
            'due_date' => '2025-09-01',
            'status' => 'pending',
        ]);

        // تنازل عن 50 د.ت
        $this->waiverService->waive($fee, 50.0, 'مساعدة اجتماعية', $user->id);
        $fee->refresh();
        $this->assertSame(30.0, $fee->outstanding());

        // محاولة تنازل ثانٍ بمبلغ 40 د.ت بينما المتبقي 30 د.ت فقط
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('يتجاوز المتبقّي');
        $this->waiverService->waive($fee, 40.0, 'تنازل إضافي مفرط', $user->id);
    }

    public function test_monthly_discount_cannot_be_edited_after_partial_payment(): void
    {
        $data = $this->setupStudentAndPlan(100.0);
        $user = $this->makeUser('admin');

        $discount = $this->discountService->createDiscount(
            $data['enrollment']->id,
            MonthlyDiscount::TYPE_NORMAL_MONTHLY,
            20.0,
            'تخفيض أصلي',
            null,
            $user->id,
            '2025-09',
            '2026-06'
        );

        $fee = StudentFee::create([
            'enrollment_id' => $data['enrollment']->id,
            'fee_type_id' => $data['tuitionType']->id,
            'description' => 'القسط الشهري — سبتمبر 2025',
            'amount_due' => 80.0,
            'due_date' => '2025-09-01',
            'status' => 'pending',
        ]);

        // سداد دفعة جزئية قدرها 40 د.ت
        $payment = Payment::create([
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'amount' => 40.0,
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'months' => ['2025-09'],
            'created_by' => $user->id,
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'student_fee_id' => $fee->id,
            'amount_allocated' => 40.0,
        ]);
        $this->paymentService->recalculateStudentFeeStatus($fee->id);

        // قاعدة الأمان: وجود دفعات نشطة على هذا الشهر يمنع تعديل أو إلغاء الخصم الأصلي مباشرة
        $hasActivePayments = PaymentAllocation::where('student_fee_id', $fee->id)
            ->whereHas('payment', fn($q) => $q->whereNull('cancelled_at'))
            ->exists();

        $this->assertTrue($hasActivePayments);

        $this->expectException(InvalidArgumentException::class);
        if ($hasActivePayments) {
            throw new InvalidArgumentException('لا يمكن تعديل أو إلغاء التخفيض الشهري بعد سداد دفعات على هذا الشهر؛ يجب معالجة المتبقي عبر التنازل');
        }
    }

    public function test_waiver_after_partial_payment_applies_only_to_remaining_balance(): void
    {
        $data = $this->setupStudentAndPlan(100.0);
        $user = $this->makeUser('admin');

        $fee = StudentFee::create([
            'enrollment_id' => $data['enrollment']->id,
            'fee_type_id' => $data['tuitionType']->id,
            'description' => 'القسط الشهري — سبتمبر 2025',
            'amount_due' => 80.0,
            'due_date' => '2025-09-01',
            'status' => 'pending',
        ]);

        // دفع 50 د.ت
        $payment = Payment::create([
            'student_id' => $data['student']->id,
            'enrollment_id' => $data['enrollment']->id,
            'amount' => 50.0,
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'months' => ['2025-09'],
            'created_by' => $user->id,
        ]);
        PaymentAllocation::create(['payment_id' => $payment->id, 'student_fee_id' => $fee->id, 'amount_allocated' => 50.0]);
        $this->paymentService->recalculateStudentFeeStatus($fee->id);

        $this->assertSame(30.0, $fee->fresh()->outstanding());

        // التنازل يقتصر على الـ 30 د.ت المتبقية
        $waiver = $this->waiverService->waive($fee, 30.0, 'إعفاء المتبقي لظرف قاهر', $user->id);
        $this->assertSame(30.0, (float) $waiver->amount);

        $fee->refresh();
        $this->assertSame('paid', $fee->status);
        $this->assertSame(0.0, $fee->outstanding());
    }

    public function test_closed_fee_cannot_receive_discount_without_finance_override(): void
    {
        $data = $this->setupStudentAndPlan(100.0);
        $user = $this->makeUser('admin');

        $fee = StudentFee::create([
            'enrollment_id' => $data['enrollment']->id,
            'fee_type_id' => $data['tuitionType']->id,
            'description' => 'القسط الشهري — سبتمبر 2025',
            'amount_due' => 100.0,
            'due_date' => '2025-09-01',
            'status' => 'pending',
        ]);

        // دفع كامل المبلغ 100 د.ت
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

        $this->assertSame(0.0, $fee->fresh()->outstanding());

        // محاولة التنازل عن رسم مسدد بالكامل
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('لا يوجد مبلغ');
        $this->waiverService->waive($fee, 20.0, 'تنازل متأخر', $user->id);
    }

    public function test_discount_has_required_reason_notes_and_actor_audit(): void
    {
        $data = $this->setupStudentAndPlan(100.0);
        $user = $this->makeUser('admin');

        $discount = $this->discountService->createDiscount(
            $data['enrollment']->id,
            MonthlyDiscount::TYPE_NORMAL_MONTHLY,
            15.0,
            'تخفيض سنوي موثق',
            'ملاحظة تدقيق',
            $user->id
        );

        $this->assertSame('تخفيض سنوي موثق', $discount->reason);
        $this->assertSame('ملاحظة تدقيق', $discount->notes);
        $this->assertSame($user->id, $discount->created_by);
        $this->assertNotNull($discount->created_at);
    }

    public function test_preview_is_recalculated_inside_collection_transaction(): void
    {
        $data = $this->setupStudentAndPlan(100.0);
        $user = $this->makeUser('admin');

        // محاكاة سيناريو: قراءة أولى
        $preview1 = $this->collectionService->preview($data['enrollment']->id, ['2025-09'], $data['tuitionType']->id);
        $this->assertSame(100.0, (float) $preview1['remaining_amount']);

        // إضافة تخفيض
        $this->discountService->createDiscount(
            $data['enrollment']->id,
            MonthlyDiscount::TYPE_NORMAL_MONTHLY,
            20.0,
            'تخفيض متزامن',
            null,
            $user->id
        );

        // إعادة الحساب المؤكدة داخل المعاملة
        $preview2 = $this->collectionService->preview($data['enrollment']->id, ['2025-09'], $data['tuitionType']->id);
        $this->assertSame(80.0, (float) $preview2['remaining_amount']);
        $this->assertNotEquals($preview1['remaining_amount'], $preview2['remaining_amount']);
    }
}
