<?php
namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IncrementalSuppliesPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_paying_registration_then_supplies_in_two_steps_without_cancel(): void
    {
        $user = $this->makeUser('admin');
        $user->update(['is_active' => true]);
        Sanctum::actingAs($user);
        $old = $this->makeEnrollment();
        $this->startNewYear();
        $this->makeFeeTypes();

        // 1) دفع الترسيم 70 اليوم
        $this->postJson('/api/students/' . $old->student_id . '/reenroll', [
            'client_request_id' => 'req-reg-70',
            'section_id' => $old->section_id,
            'registration_amount' => 70,
            'payment_method' => 'cash',
            'payment_date' => '2026-09-20',
            'fee_items' => [
                ['fee_type_id' => $this->regFee->id, 'amount' => 70, 'description' => 'معلوم الترسيم'],
            ],
        ])->assertCreated();
        $this->assertEquals(70, CashTransaction::whereNull('cancelled_at')->sum('amount'));

        // 2) دفع المستلزمات 90 غدا لنفس الترسيم — يجب أن ينجح دون إلغاء
        $this->postJson('/api/students/' . $old->student_id . '/registration-payment', [
            'client_request_id' => 'req-supplies-90',
            'registration_amount' => 90,
            'payment_method' => 'cash',
            'payment_date' => '2026-09-21',
            'fee_items' => [
                ['fee_type_id' => $this->blouseFee->id, 'amount' => 30, 'description' => 'ميدعة'],
                ['fee_type_id' => $this->equipmentFee->id, 'amount' => 40, 'description' => 'تجهيزات'],
                ['fee_type_id' => $this->cnFee->id, 'amount' => 20, 'description' => 'CNTE'],
            ],
        ])->assertCreated();

        // الخزينة اليوم الثاني يجب أن تضيف 90 فقط، لا 160
        $this->assertEquals(160, CashTransaction::whereNull('cancelled_at')->sum('amount'));
        $this->assertEquals(2, \App\Models\Payment::whereNull('cancelled_at')->count());
        // لا يوجد إلغاء
        $this->assertEquals(0, CashTransaction::whereNotNull('cancelled_at')->count());
    }

    private $regFee; private $blouseFee; private $equipmentFee; private $cnFee;
    private function makeFeeTypes(): void
    {
        $this->regFee = FeeType::create(['name_ar' => 'معلوم الترسيم', 'price' => 70, 'ledger_category' => CashTransaction::CATEGORY_REGISTRATION_FEE, 'is_active' => true]);
        $this->blouseFee = FeeType::create(['name_ar' => 'ميدعة', 'price' => 30, 'ledger_category' => CashTransaction::CATEGORY_PRODUCT_SALE, 'is_active' => true]);
        $this->equipmentFee = FeeType::create(['name_ar' => 'تجهيزات', 'price' => 40, 'ledger_category' => CashTransaction::CATEGORY_PRODUCT_SALE, 'is_active' => true]);
        $this->cnFee = FeeType::create(['name_ar' => 'CNTE', 'price' => 20, 'ledger_category' => CashTransaction::CATEGORY_OTHER_INCOME, 'is_active' => true]);
    }
    private function startNewYear(): AcademicYear
    {
        AcademicYear::query()->update(['is_active' => false]);
        return AcademicYear::create(['name' => '2026-2027', 'start_date' => '2026-09-15', 'end_date' => '2027-06-30', 'is_active' => true]);
    }
    protected function makeEnrollment(?\App\Models\AcademicYear $year = null, ?\App\Models\Student $student = null): \App\Models\Enrollment
    {
        return parent::makeEnrollment($year, $student);
    }
}
