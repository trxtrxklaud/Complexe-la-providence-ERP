<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\StudentFee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * معلوم تجديد الترسيم.
 *
 * الخلل الذي تحرسه هذه الاختبارات وقع فعلاً ثلاث مرّات:
 *   1) حقول الدفع كانت معروضة في الشاشة ولا تُرسَل إلى الخادم أصلاً.
 *   2) المبلغ المقبوض كان يسقط في «مداخيل أخرى» لأنّ الدفعة بلا تخصيص رسم،
 *      فيدخل المال الخزينة ويغيب عن معاليم التسجيل وعن كل تقرير يفصّل حسب البند.
 *   3) وبعد التخصيص، سعر نوع الرسم كان يسقّفه: دفعة 70 د على نوع سعره 20 د
 *      أنتجت 20 معاليم تسجيل و50 مداخيل أخرى دون خطأ في أي مجموع.
 *
 * الدفتر النقدي هو المرجع الوحيد للسجل اليومي والشهري والدخل الصافي،
 * فإثبات السطر هنا يغني عن إثباته في الثلاثة.
 */
class ReenrollRegistrationPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_reenrolling_with_an_amount_posts_a_registration_fee_line(): void
    {
        Sanctum::actingAs($this->makeRegistrar());

        $old = $this->makeEnrollment();
        $this->startNewYear();
        $this->makeRegistrationFeeType(150);

        $response = $this->postJson('/api/students/' . $old->student_id . '/reenroll', [
            'client_request_id' => 'req-test-150',
            'section_id' => $old->section_id,
            'registration_amount' => 150,
            'payment_method' => 'cash',
            'payment_date' => '2026-08-03',
        ]);

        $response->assertCreated();

        $transaction = CashTransaction::firstOrFail();

        $this->assertSame(
            CashTransaction::CATEGORY_REGISTRATION_FEE,
            $transaction->category,
            'معلوم التجديد ليس «مدخولاً آخر»: تصنيفه هو ما تقرأه التقارير'
        );
        $this->assertSame(CashTransaction::DIRECTION_IN, $transaction->direction);
        $this->assertEqualsWithDelta(150, (float) $transaction->amount, 0.001);
        $this->assertSame('2026-08-03', $transaction->transaction_date->toDateString());
        $this->assertNull($transaction->cancelled_at);

        $payment = Payment::firstOrFail();

        $this->assertEqualsWithDelta(150, (float) $payment->amount, 0.001);
        $this->assertSame('cash', $payment->method);
    }

    /**
     * المقبوض أكبر من سعر نوع الرسم — وهي الحالة التي أنتجت العيب في الإنتاج.
     *
     * الأسعار في fee_types مرجع افتراضي، والمدرسة تقبض مبالغ تختلف باختلاف
     * المستوى والتلميذ. تسقيف التصنيف بالسعر ينقل الفارق إلى «مداخيل أخرى»
     * فتقرأ الإدارة معاليم تسجيل أقلّ من الحقيقة دون أن يختلّ أي مجموع.
     */
    public function test_an_amount_above_the_fee_type_price_stays_registration_fee(): void
    {
        Sanctum::actingAs($this->makeRegistrar());

        $old = $this->makeEnrollment();
        $this->startNewYear();
        $feeType = $this->makeRegistrationFeeType(20);

        $this->postJson('/api/students/' . $old->student_id . '/reenroll', [
            'client_request_id' => 'req-test-70',
            'section_id' => $old->section_id,
            'registration_amount' => 70,
            'payment_method' => 'cash',
            'payment_date' => '2026-08-03',
        ])->assertCreated();

        $this->assertSame(
            0,
            CashTransaction::where('category', CashTransaction::CATEGORY_OTHER_INCOME)->count(),
            'الفائض عن السعر كان يفتح سطر «مداخيل أخرى» موازياً'
        );
        $this->assertSame(1, CashTransaction::count(), 'دفعة واحدة ببند واحد لا ببندين');

        $transaction = CashTransaction::firstOrFail();

        $this->assertSame(CashTransaction::CATEGORY_REGISTRATION_FEE, $transaction->category);
        $this->assertEqualsWithDelta(70, (float) $transaction->amount, 0.001);

        $fee = StudentFee::where('fee_type_id', $feeType->id)->firstOrFail();

        $this->assertEqualsWithDelta(
            70,
            (float) $fee->amount_due,
            0.001,
            'ملفّ التلميذ يجب أن يوثّق ما طولب به فعلاً'
        );
        $this->assertSame('paid', $fee->status, 'رسم خُلّص بكامله لا يجوز أن يبقى ديناً');
    }

    public function test_the_new_ledger_line_carries_the_new_academic_year(): void
    {
        Sanctum::actingAs($this->makeRegistrar());

        $old = $this->makeEnrollment();
        $newYear = $this->startNewYear();
        $this->makeRegistrationFeeType(150);

        $this->postJson('/api/students/' . $old->student_id . '/reenroll', [
            'client_request_id' => 'req-test-120',
            'section_id' => $old->section_id,
            'registration_amount' => 120,
            'payment_method' => 'cash',
            'payment_date' => '2026-08-03',
        ])->assertCreated();

        $this->assertSame(
            $newYear->id,
            CashTransaction::firstOrFail()->academic_year_id,
            'سطر بلا سنة يسقط خارج التقارير السنوية'
        );
    }

    public function test_reenrolling_without_an_amount_creates_no_ledger_line(): void
    {
        Sanctum::actingAs($this->makeRegistrar());

        $old = $this->makeEnrollment();
        $this->startNewYear();
        $this->makeRegistrationFeeType(150);

        $this->postJson('/api/students/' . $old->student_id . '/reenroll', [
            'section_id' => $old->section_id,
        ])->assertCreated();

        $this->assertSame(2, Enrollment::count(), 'الترسيم يقع ولو لم يُقبض مال اليوم');
        $this->assertSame(0, CashTransaction::count(), 'لا يجوز تسجيل مدخول لم يُقبض');
        $this->assertSame(0, Payment::count());
    }

    public function test_an_amount_without_a_method_is_refused_and_nothing_is_written(): void
    {
        Sanctum::actingAs($this->makeRegistrar());

        $old = $this->makeEnrollment();
        $this->startNewYear();
        $this->makeRegistrationFeeType(150);

        $this->postJson('/api/students/' . $old->student_id . '/reenroll', [
            'client_request_id' => 'req-no-method',
            'section_id' => $old->section_id,
            'registration_amount' => 150,
        ])->assertUnprocessable();

        $this->assertSame(1, Enrollment::count(), 'الطلب ُردّ قبل أي كتابة، فلا يبقى ترسيم يتيم');
        $this->assertSame(0, CashTransaction::count());
    }

    public function test_a_second_reenrollment_in_the_same_year_is_refused(): void
    {
        Sanctum::actingAs($this->makeRegistrar());

        $old = $this->makeEnrollment();
        $this->startNewYear();
        $this->makeRegistrationFeeType(150);

        $payload = [
            'client_request_id' => 'req-sec-1',
            'section_id' => $old->section_id,
            'registration_amount' => 150,
            'payment_method' => 'cash',
            'payment_date' => '2026-08-03',
        ];

        $this->postJson('/api/students/' . $old->student_id . '/reenroll', $payload)->assertCreated();
        $this->postJson('/api/students/' . $old->student_id . '/reenroll', array_merge($payload, ['client_request_id' => 'req-sec-2']))->assertStatus(422);

        $this->assertSame(2, Enrollment::count(), 'ترسيم مزدوج في نفس السنة يضاعف الرسوم');
        $this->assertSame(1, CashTransaction::count(), 'ويضاعف المدخول معها');
    }

    public function test_reenrolling_with_detailed_fee_items_posts_proper_ledger_categories(): void
    {
        Sanctum::actingAs($this->makeRegistrar());

        $old = $this->makeEnrollment();
        $this->startNewYear();

        $ftReg = FeeType::create([
            'name_ar' => 'معلوم الترسيم',
            'price' => 70,
            'ledger_category' => CashTransaction::CATEGORY_REGISTRATION_FEE,
            'is_active' => true,
        ]);
        $ftBlouse = FeeType::create([
            'name_ar' => 'ميدعة',
            'price' => 30,
            'ledger_category' => CashTransaction::CATEGORY_PRODUCT_SALE,
            'is_active' => true,
        ]);
        $ftVie = FeeType::create([
            'name_ar' => 'ERP vie scolaire',
            'price' => 20,
            'ledger_category' => CashTransaction::CATEGORY_OTHER_INCOME,
            'is_active' => true,
        ]);
        $ftPaper = FeeType::create([
            'name_ar' => 'رزمة أوراق',
            'price' => 15,
            'ledger_category' => CashTransaction::CATEGORY_PRODUCT_SALE,
            'is_active' => true,
        ]);

        $payload = [
            'client_request_id' => 'req-detailed-items',
            'section_id' => $old->section_id,
            'registration_amount' => 135, // 70 + 30 + 20 + 15
            'payment_method' => 'cash',
            'payment_date' => '2026-08-31',
            'payment_notes' => 'ترسيم ولوازم كاملة',
            'fee_items' => [
                ['fee_type_id' => $ftReg->id, 'amount' => 70, 'description' => 'معلوم الترسيم'],
                ['fee_type_id' => $ftBlouse->id, 'amount' => 30, 'description' => 'ميدعة'],
                ['fee_type_id' => $ftVie->id, 'amount' => 20, 'description' => 'ERP vie scolaire'],
                ['fee_type_id' => $ftPaper->id, 'amount' => 15, 'description' => 'رزمة أوراق'],
            ],
        ];

        $response = $this->postJson('/api/students/' . $old->student_id . '/reenroll', $payload);
        $response->assertCreated();

        // تحقق من تسجيل القيود في الدفتر النقدي مصنفة بحسب البنود:
        // 1. معاليم التسجيل: 70 د.ت
        // 2. بيع المنتجات (ميدعة + ورق): 30 + 15 = 45 د.ت
        // 3. مداخيل أخرى (vie scolaire): 20 د.ت
        $this->assertEqualsWithDelta(
            70,
            (float) CashTransaction::where('category', CashTransaction::CATEGORY_REGISTRATION_FEE)->value('amount'),
            0.001
        );
        $this->assertEqualsWithDelta(
            45,
            (float) CashTransaction::where('category', CashTransaction::CATEGORY_PRODUCT_SALE)->value('amount'),
            0.001
        );
        $this->assertEqualsWithDelta(
            20,
            (float) CashTransaction::where('category', CashTransaction::CATEGORY_OTHER_INCOME)->value('amount'),
            0.001
        );

        $this->assertEqualsWithDelta(135, (float) CashTransaction::sum('amount'), 0.001);
    }

    public function test_custom_price_adjustments_and_unseeded_fee_types_are_accepted_without_validation_errors(): void
    {
        Sanctum::actingAs($this->makeRegistrar());

        $old = $this->makeEnrollment();
        $this->startNewYear();

        // تجربة إرسال مبالغ معدلة من المستخدم وبنود دون معرف مسبق
        $payload = [
            'client_request_id' => 'req-custom-prices',
            'section_id' => $old->section_id,
            'registration_amount' => 160, // 70 + 50 + 40
            'payment_method' => 'cash',
            'payment_date' => '2026-08-31',
            'fee_items' => [
                ['fee_type_id' => null, 'amount' => 70, 'description' => 'معلوم الترسيم', 'category' => 'registration_fee'],
                ['fee_type_id' => 99999, 'amount' => 50, 'description' => 'الميدعة المدرسية', 'category' => 'product_sale'], // معرف غير موجود
                ['fee_type_id' => null, 'amount' => 40, 'description' => 'رزمة أوراق الطباعة', 'category' => 'product_sale'], // سعر مخصص 40 د.ت
            ],
        ];

        $response = $this->postJson('/api/students/' . $old->student_id . '/reenroll', $payload);
        $response->assertCreated();
        $this->assertEqualsWithDelta(160, (float) CashTransaction::sum('amount'), 0.001);
    }

    /** سنة دراسية جديدة نشطة: بدونها يعتبر الخادم التلميذ مُرسّماً فيرفض التجديد. */
    private function startNewYear(): AcademicYear
    {
        AcademicYear::query()->update(['is_active' => false]);

        return AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-09-15',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);
    }

    private function makeRegistrationFeeType(float $price): FeeType
    {
        return FeeType::updateOrCreate(
            ['name_ar' => 'معلوم الترسيم'],
            [
                'price' => $price,
                'ledger_category' => CashTransaction::CATEGORY_REGISTRATION_FEE,
                'is_active' => true,
            ]
        );
    }

    private function makeRegistrar(): User
    {
        $user = $this->makeUser('registrar');
        $user->update(['is_active' => true]);

        $permission = Permission::firstOrCreate(
            ['name' => 'manage_students'],
            ['display_name' => 'إدارة التلاميذ', 'group' => 'Students']
        );

        $user->role->permissions()->syncWithoutDetaching([$permission->id]);

        return $user;
    }
}
