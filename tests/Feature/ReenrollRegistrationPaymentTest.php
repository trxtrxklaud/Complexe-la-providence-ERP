<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * معلوم تجديد الترسيم.
 *
 * الخلل الذي تحرسه هذه الاختبارات وقع فعلاً مرّتين:
 *   1) حقول الدفع كانت معروضة في الشاشة ولا تُرسَل إلى الخادم أصلاً.
 *   2) المبلغ المقبوض كان يسقط في «مداخيل أخرى» لأنّ الدفعة بلا تخصيص رسم،
 *      فيدخل المال الخزينة ويغيب عن معاليم التسجيل وعن كل تقرير يفصّل حسب البند.
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

    public function test_the_new_ledger_line_carries_the_new_academic_year(): void
    {
        Sanctum::actingAs($this->makeRegistrar());

        $old = $this->makeEnrollment();
        $newYear = $this->startNewYear();
        $this->makeRegistrationFeeType(150);

        $this->postJson('/api/students/' . $old->student_id . '/reenroll', [
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
            'section_id' => $old->section_id,
            'registration_amount' => 150,
            'payment_method' => 'cash',
            'payment_date' => '2026-08-03',
        ];

        $this->postJson('/api/students/' . $old->student_id . '/reenroll', $payload)->assertCreated();
        $this->postJson('/api/students/' . $old->student_id . '/reenroll', $payload)->assertStatus(422);

        $this->assertSame(2, Enrollment::count(), 'ترسيم مزدوج في نفس السنة يضاعف الرسوم');
        $this->assertSame(1, CashTransaction::count(), 'ويضاعف المدخول معها');
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
        return FeeType::create([
            'name_ar' => 'معلوم الترسيم',
            'price' => $price,
            'ledger_category' => CashTransaction::CATEGORY_REGISTRATION_FEE,
            'is_active' => true,
        ]);
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
