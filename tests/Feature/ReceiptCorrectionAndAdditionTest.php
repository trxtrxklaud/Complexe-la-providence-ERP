<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\FeeType;
use App\Models\Level;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReceiptCorrectionAndAdditionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected AcademicYear $academicYear;
    protected FeeType $registrationFeeType;
    protected FeeType $suppliesFeeType;
    protected FeeType $tuitionFeeType;
    protected Student $student;
    protected Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. صلاحيات المستخدم
        $permManage = Permission::firstOrCreate([
            'name'         => 'manage_payments',
            'display_name' => 'إدارة التحصيل والدفعات',
            'module'       => 'finance',
        ]);
        $permTreasury = Permission::firstOrCreate([
            'name'         => 'manage_treasury',
            'display_name' => 'إدارة الخزينة',
            'module'       => 'finance',
        ]);
        $permReports = Permission::firstOrCreate([
            'name'         => 'view_reports',
            'display_name' => 'عرض التقارير المالية',
            'module'       => 'finance',
        ]);

        $role = Role::firstOrCreate([
            'name'         => 'admin',
            'display_name' => 'مدير النظام',
        ]);
        $role->permissions()->syncWithoutDetaching([$permManage->id, $permTreasury->id, $permReports->id]);

        $this->user = User::create([
            'role_id'    => $role->id,
            'username'   => 'finance_officer',
            'first_name' => 'علي',
            'last_name'  => 'المنصوري',
            'email'      => 'officer@school.test',
            'password'   => bcrypt('password'),
            'is_active'  => true,
        ]);

        // 2. سنة دراسية نشطة
        $this->academicYear = AcademicYear::create([
            'name'       => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date'   => '2027-06-30',
            'is_active'  => true,
        ]);

        // 3. فئات وأنواع الرسوم
        $category = FeeCategory::create([
            'name'         => 'المعاليم المدرسية',
            'code'         => 'TUITION_MONTHLY',
            'is_recurring' => true,
        ]);

        $this->registrationFeeType = FeeType::create([
            'fee_category_id' => $category->id,
            'name_ar'         => 'معلوم الترسيم',
            'price'           => 70.00,
            'ledger_category' => CashTransaction::CATEGORY_REGISTRATION_FEE,
            'is_active'       => true,
        ]);

        $this->suppliesFeeType = FeeType::create([
            'fee_category_id' => $category->id,
            'name_ar'         => 'مستلزمات مدرسية',
            'price'           => 20.00,
            'ledger_category' => CashTransaction::CATEGORY_PRODUCT_SALE,
            'is_active'       => true,
        ]);

        $this->tuitionFeeType = FeeType::create([
            'fee_category_id' => $category->id,
            'name_ar'         => 'معلوم التمدرس الشهري',
            'price'           => 170.00,
            'ledger_category' => CashTransaction::CATEGORY_MONTHLY_FEE,
            'is_active'       => true,
        ]);

        // 4. مستوى وقسم وتلميذ وتسجيل
        $level = Level::create(['name' => 'السنة الأولى', 'code' => 'L1', 'order' => 1]);
        $section = Section::create(['name' => 'أولى أ', 'code' => 'L1-A', 'level_id' => $level->id]);

        $this->student = Student::create([
            'first_name'   => 'يوسف',
            'last_name'    => 'بن عمر',
            'student_code' => 'STU-2026-001',
            'gender'       => 'male',
            'status'       => 'enrolled',
        ]);

        $this->enrollment = Enrollment::create([
            'student_id'       => $this->student->id,
            'academic_year_id' => $this->academicYear->id,
            'level_id'         => $level->id,
            'section_id'       => $section->id,
            'status'           => 'enrolled',
            'enrollment_date'  => '2026-09-01',
        ]);
    }

    /** 1. وصل 170 يُصحح إلى 160 في نفس اليوم: قيد واحد بتاريخ اليوم = 160. */
    public function test_01_receipt_170_corrected_to_160_same_day_single_entry_equals_160(): void
    {
        $today = '2026-09-25';
        Carbon::setTestNow($today);

        // إنشاء وصل بمبلغ 170 اليوم
        $fee = StudentFee::create([
            'enrollment_id' => $this->enrollment->id,
            'fee_type_id'   => $this->tuitionFeeType->id,
            'amount_due'    => 170.00,
            'due_date'      => $today,
            'description'   => 'معلوم التمدرس — سبتمبر 2026',
            'status'        => 'pending',
        ]);

        $res = $this->actingAs($this->user)->postJson('/api/payments', [
            'student_id'    => $this->student->id,
            'enrollment_id' => $this->enrollment->id,
            'amount'        => 170.00,
            'payment_date'  => $today,
            'method'        => 'cash',
            'allocations'   => [
                ['student_fee_id' => $fee->id, 'amount' => 170.00],
            ],
        ]);
        $res->assertCreated();
        $paymentId = $res->json('id');

        // تأكيد وجود قيد واحد بمبلغ 170 وتاريخ اليوم
        $txsBefore = CashTransaction::where('source_type', Payment::class)
            ->where('source_id', $paymentId)
            ->whereNull('cancelled_at')
            ->get();
        $this->assertCount(1, $txsBefore);
        $this->assertEquals(170.00, (float) $txsBefore->first()->amount);
        $this->assertEquals($today, $txsBefore->first()->transaction_date->toDateString());

        // تصحيح مبلغ الوصل إلى 160 في نفس اليوم
        $correctRes = $this->actingAs($this->user)->postJson("/api/payments/{$paymentId}/correct", [
            'amount' => 160.00,
            'reason' => 'تصحيح خطأ تسجيل: الولي دفع 160 وليس 170',
        ]);
        $correctRes->assertOk();

        // التحقق: قيد واحد فقط بتاريخ اليوم = 160
        $txsAfter = CashTransaction::where('source_type', Payment::class)
            ->where('source_id', $paymentId)
            ->whereNull('cancelled_at')
            ->get();
        $this->assertCount(1, $txsAfter);
        $tx = $txsAfter->first();
        $this->assertEquals(160.00, (float) $tx->amount);
        $this->assertEquals($today, $tx->transaction_date->toDateString());

        // التحقق من حفظ الحقول الإلزامية: old_amount و new_amount و edited_by و edited_at
        $payment = Payment::find($paymentId);
        $this->assertEquals(160.00, (float) $payment->amount);
        $this->assertEquals(170.00, (float) $payment->old_amount);
        $this->assertEquals(160.00, (float) $payment->new_amount);
        $this->assertEquals($this->user->id, $payment->edited_by);
        $this->assertNotNull($payment->edited_at);
    }

    /** 2. نفس التصحيح في يوم لاحق: قيد اليوم الأصلي = 160، ويوم التعديل بلا حركة. */
    public function test_02_same_correction_on_later_day_original_day_160_edit_day_no_movement(): void
    {
        $originalDate = '2026-09-10';
        Carbon::setTestNow($originalDate);

        // إنشاء وصل بمبلغ 170 بتاريخ 2026-09-10
        $fee = StudentFee::create([
            'enrollment_id' => $this->enrollment->id,
            'fee_type_id'   => $this->tuitionFeeType->id,
            'amount_due'    => 170.00,
            'due_date'      => $originalDate,
            'description'   => 'معلوم التمدرس — سبتمبر 2026',
            'status'        => 'pending',
        ]);

        $res = $this->actingAs($this->user)->postJson('/api/payments', [
            'student_id'    => $this->student->id,
            'enrollment_id' => $this->enrollment->id,
            'amount'        => 170.00,
            'payment_date'  => $originalDate,
            'method'        => 'cash',
            'allocations'   => [
                ['student_fee_id' => $fee->id, 'amount' => 170.00],
            ],
        ]);
        $res->assertCreated();
        $paymentId = $res->json('id');

        // الانتقال إلى يوم لاحق (2026-09-25)
        $editDate = '2026-09-25';
        Carbon::setTestNow($editDate);

        // تصحيح مبلغ الوصل إلى 160 في اليوم اللاحق
        $correctRes = $this->actingAs($this->user)->putJson("/api/payments/{$paymentId}", [
            'amount' => 160.00,
            'reason' => 'تصحيح متأخر: القيد الأصلي كان 170 والصحيح 160',
        ]);
        $correctRes->assertOk();

        // التحقق: قيد اليوم الأصلي (2026-09-10) أصبح 160
        $originalTx = CashTransaction::where('source_type', Payment::class)
            ->where('source_id', $paymentId)
            ->whereDate('transaction_date', $originalDate)
            ->whereNull('cancelled_at')
            ->first();
        $this->assertNotNull($originalTx);
        $this->assertEquals(160.00, (float) $originalTx->amount);

        // التحقق: يوم التعديل (2026-09-25) بلا أي حركة لهذا الوصل
        $editDayTxs = CashTransaction::where('source_type', Payment::class)
            ->where('source_id', $paymentId)
            ->whereDate('transaction_date', $editDate)
            ->whereNull('cancelled_at')
            ->get();
        $this->assertCount(0, $editDayTxs);

        // إجمالي القيود النشطة لهذا الوصل يبقى 1 فقط
        $allTxs = CashTransaction::where('source_type', Payment::class)
            ->where('source_id', $paymentId)
            ->whereNull('cancelled_at')
            ->get();
        $this->assertCount(1, $allTxs);

        // التحقق من كشف الخزينة اليومي (Daybook):
        // اليوم الأصلي يعكس 160
        $daybookOriginal = $this->actingAs($this->user)->getJson("/api/reports/treasury-daybook?date={$originalDate}");
        $daybookOriginal->assertOk();
        $days = $daybookOriginal->json('days');
        $this->assertNotEmpty($days);
        $dayCard = collect($days)->firstWhere('date', $originalDate);
        $this->assertNotNull($dayCard);
        $this->assertEquals(160.00, (float) ($dayCard['income']['total'] ?? 0));
    }

    /** 3. ترسيم قديم ثم إضافة مستلزمات بتاريخ قديم: قيد ترسيم قديم + قيد مستلزمات جديد. */
    public function test_03_old_registration_then_add_supplies_with_old_date_old_reg_and_new_supplies_entries(): void
    {
        $regDate = '2026-08-15';
        Carbon::setTestNow($regDate);

        // 1. ترسيم قديم بمبلغ 70 د.ت
        $regFee = StudentFee::create([
            'enrollment_id' => $this->enrollment->id,
            'fee_type_id'   => $this->registrationFeeType->id,
            'amount_due'    => 70.00,
            'due_date'      => $regDate,
            'description'   => 'معلوم الترسيم',
            'status'        => 'pending',
        ]);

        $resReg = $this->actingAs($this->user)->postJson('/api/payments', [
            'student_id'    => $this->student->id,
            'enrollment_id' => $this->enrollment->id,
            'amount'        => 70.00,
            'payment_date'  => $regDate,
            'method'        => 'cash',
            'allocations'   => [
                ['student_fee_id' => $regFee->id, 'amount' => 70.00],
            ],
        ]);
        $resReg->assertCreated();
        $regPaymentId = $resReg->json('id');

        // تأكيد قيد الترسيم القديم في الخزينة
        $regTx = CashTransaction::where('source_type', Payment::class)
            ->where('source_id', $regPaymentId)
            ->whereNull('cancelled_at')
            ->first();
        $this->assertNotNull($regTx);
        $this->assertEquals(70.00, (float) $regTx->amount);
        $this->assertEquals($regDate, $regTx->transaction_date->toDateString());
        $this->assertEquals(CashTransaction::CATEGORY_REGISTRATION_FEE, $regTx->category);

        // 2. إضافة مستلزمات بـ 20 د.ت بتاريخ قديم (2026-08-25)
        $suppliesDate = '2026-08-25';
        Carbon::setTestNow('2026-09-25'); // التاريخ الحالي في النظام

        $addRes = $this->actingAs($this->user)->postJson("/api/payments/{$regPaymentId}/add-item", [
            'fee_type_id'   => $this->suppliesFeeType->id,
            'amount'        => 20.00,
            'addition_date' => $suppliesDate,
            'description'   => 'ميدعة ومستلزمات مدرسية',
        ]);
        $addRes->assertCreated();

        // التحقق: قيد ترسيم قديم + قيد مستلزمات جديد
        // أ) قيد الترسيم لم يتغير تاريخه ولا مبلغه ولا تصنيفه
        $regTxFresh = $regTx->fresh();
        $this->assertEquals(70.00, (float) $regTxFresh->amount);
        $this->assertEquals($regDate, $regTxFresh->transaction_date->toDateString());
        $this->assertEquals(CashTransaction::CATEGORY_REGISTRATION_FEE, $regTxFresh->category);

        // ب) قيد مستلزمات جديد بالمبلغ المضاف (20) وتاريخ الإضافة (2026-08-25) وتصنيف product_sale
        $suppliesTx = CashTransaction::where('category', CashTransaction::CATEGORY_PRODUCT_SALE)
            ->whereDate('transaction_date', $suppliesDate)
            ->whereNull('cancelled_at')
            ->first();
        $this->assertNotNull($suppliesTx);
        $this->assertEquals(20.00, (float) $suppliesTx->amount);
        $this->assertEquals($suppliesDate, $suppliesTx->transaction_date->toDateString());
        $this->assertEquals(CashTransaction::CATEGORY_PRODUCT_SALE, $suppliesTx->category);

        // إجمالي القيود = 2 (ترسيم 70 + مستلزمات 20)
        $totalCash = CashTransaction::whereNull('cancelled_at')->sum('amount');
        $this->assertEquals(90.00, (float) $totalCash);
    }

    /** 4. تكرار نفس التصحيح لا ينشئ قيداً آخر. */
    public function test_04_duplicate_same_correction_creates_no_additional_entry(): void
    {
        $today = '2026-09-25';
        Carbon::setTestNow($today);

        $fee = StudentFee::create([
            'enrollment_id' => $this->enrollment->id,
            'fee_type_id'   => $this->tuitionFeeType->id,
            'amount_due'    => 170.00,
            'due_date'      => $today,
            'description'   => 'معلوم التمدرس — سبتمبر 2026',
            'status'        => 'pending',
        ]);

        $res = $this->actingAs($this->user)->postJson('/api/payments', [
            'student_id'    => $this->student->id,
            'enrollment_id' => $this->enrollment->id,
            'amount'        => 170.00,
            'payment_date'  => $today,
            'method'        => 'cash',
            'allocations'   => [
                ['student_fee_id' => $fee->id, 'amount' => 170.00],
            ],
        ]);
        $paymentId = $res->json('id');

        // تصحيح أول: 160
        $this->actingAs($this->user)->postJson("/api/payments/{$paymentId}/correct", [
            'amount' => 160.00,
        ])->assertOk();

        $countAfterFirst = CashTransaction::where('source_type', Payment::class)
            ->where('source_id', $paymentId)
            ->whereNull('cancelled_at')
            ->count();
        $this->assertEquals(1, $countAfterFirst);

        // تكرار نفس التصحيح بنفس المبلغ (160)
        $repeatRes = $this->actingAs($this->user)->postJson("/api/payments/{$paymentId}/correct", [
            'amount' => 160.00,
        ]);
        $repeatRes->assertOk();

        // التحقق: لا يوجد أي قيد إضافي في الخزينة
        $countAfterSecond = CashTransaction::where('source_type', Payment::class)
            ->where('source_id', $paymentId)
            ->whereNull('cancelled_at')
            ->count();
        $this->assertEquals(1, $countAfterSecond);

        $tx = CashTransaction::where('source_type', Payment::class)
            ->where('source_id', $paymentId)
            ->whereNull('cancelled_at')
            ->first();
        $this->assertEquals(160.00, (float) $tx->amount);
    }

    /** 5. تقرير الدخل يفصل الترسيم عن المستلزمات. */
    public function test_05_income_report_separates_registration_from_supplies(): void
    {
        $regDate = '2026-08-15';
        $suppliesDate = '2026-08-25';

        // 1. تسجيل ترسيم (70 د.ت)
        $regFee = StudentFee::create([
            'enrollment_id' => $this->enrollment->id,
            'fee_type_id'   => $this->registrationFeeType->id,
            'amount_due'    => 70.00,
            'due_date'      => $regDate,
            'description'   => 'معلوم الترسيم',
            'status'        => 'pending',
        ]);
        $resReg = $this->actingAs($this->user)->postJson('/api/payments', [
            'student_id'    => $this->student->id,
            'enrollment_id' => $this->enrollment->id,
            'amount'        => 70.00,
            'payment_date'  => $regDate,
            'method'        => 'cash',
            'allocations'   => [['student_fee_id' => $regFee->id, 'amount' => 70.00]],
        ]);
        $regPaymentId = $resReg->json('id');

        // 2. إضافة مستلزمات (20 د.ت)
        $this->actingAs($this->user)->postJson("/api/payments/{$regPaymentId}/add-item", [
            'fee_type_id'   => $this->suppliesFeeType->id,
            'amount'        => 20.00,
            'addition_date' => $suppliesDate,
            'description'   => 'ميدعة ومستلزمات',
        ])->assertCreated();

        // 3. طلب تقرير المداخيل حسب التاريخ
        $reportRes = $this->actingAs($this->user)->getJson('/api/reports/income-by-date?' . http_build_query([
            'date_from' => '2026-08-01',
            'date_to'   => '2026-08-31',
            'granularity' => 'month',
        ]));
        $reportRes->assertOk();

        $data = $reportRes->json();
        $summary = $data['summary'] ?? [];
        $byCat = collect($summary['by_category'] ?? [])->keyBy('category');

        // التحقق: الترسيم يظهر في معاليم التسجيل (70)، والمستلزمات تظهر في بيع المنتجات (20)
        $this->assertEquals(70.00, (float) ($byCat[CashTransaction::CATEGORY_REGISTRATION_FEE]['total'] ?? 0));
        $this->assertEquals(20.00, (float) ($byCat[CashTransaction::CATEGORY_PRODUCT_SALE]['total'] ?? 0));
        $this->assertEquals(90.00, (float) ($summary['total'] ?? 0));
    }
}
