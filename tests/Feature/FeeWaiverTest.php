<?php

namespace Tests\Feature;

use App\Models\CashTransaction;
use App\Models\FeeWaiver;
use App\Models\StudentFee;
use App\Services\FeeWaiverService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeeWaiverTest extends TestCase
{
    use RefreshDatabase;

    private function makeFee(float $amountDue = 70): StudentFee
    {
        $enrollment = $this->makeEnrollment();
        $feeType = $this->makeFeeType('معلوم الترسيم', $amountDue);

        return StudentFee::create([
            'enrollment_id' => $enrollment->id,
            'fee_type_id'   => $feeType->id,
            'description'   => 'معلوم الترسيم',
            'amount_due'    => $amountDue,
            'due_date'      => '2025-09-01',
            'status'        => 'pending',
        ]);
    }

    public function test_a_waiver_closes_the_debt_without_creating_income(): void
    {
        $fee = $this->makeFee(70);
        $ledgerBefore = CashTransaction::count();

        app(FeeWaiverService::class)->waive($fee, 70, 'تنازل من صاحبة المدرسة');

        $fee->refresh();

        $this->assertSame(0.0, $fee->outstanding());
        $this->assertSame('paid', $fee->status);
        $this->assertSame(70.0, $fee->waivedAmount());
        $this->assertSame(0.0, $fee->allocatedAmount());

        // لا مدخول ولا حركة خزينة: التنازل لم يدخل مليماً.
        $this->assertSame($ledgerBefore, CashTransaction::count());
    }

    public function test_cancelling_a_waiver_gives_the_debt_back(): void
    {
        $fee = $this->makeFee(70);
        $service = app(FeeWaiverService::class);

        $waiver = $service->waive($fee, 50, 'حالة اجتماعية');
        $this->assertSame(20.0, $fee->fresh()->outstanding());

        $service->cancel($waiver, 'خطأ في الإدخال');

        $fee->refresh();
        $this->assertSame(70.0, $fee->outstanding());
        $this->assertSame(0.0, $fee->waivedAmount());
        $this->assertSame('pending', $fee->status);
        $this->assertNotNull($waiver->fresh()->cancelled_at);
    }

    public function test_a_waiver_cannot_exceed_the_outstanding_amount(): void
    {
        $fee = $this->makeFee(70);

        $this->expectException(\InvalidArgumentException::class);

        app(FeeWaiverService::class)->waive($fee, 71, 'محاولة تجاوز');
    }

    public function test_a_waiver_without_a_reason_is_refused(): void
    {
        $fee = $this->makeFee(70);

        $this->expectException(\InvalidArgumentException::class);

        app(FeeWaiverService::class)->waive($fee, 10, '   ');
    }

    public function test_waivers_and_payments_share_the_same_remainder(): void
    {
        $fee = $this->makeFee(70);
        $enrollment = $fee->enrollment;

        app(PaymentService::class)->recordPayment([
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'amount'        => 20,
            'payment_date'  => '2025-09-10',
            'method'        => 'cash',
            'allocations'   => [
                ['student_fee_id' => $fee->id, 'amount' => 20],
            ],
        ]);

        $fee->refresh();
        $this->assertSame('partial', $fee->status);
        $this->assertSame(50.0, $fee->outstanding());

        app(FeeWaiverService::class)->waive($fee, 50, 'تنازل عن المتبقّي');

        $fee->refresh();
        $this->assertSame(0.0, $fee->outstanding());
        $this->assertSame('paid', $fee->status);

        // دفعة لاحقة على مبلغ تُنوزِل عنه يجب أن تُرفض.
        $this->expectException(\InvalidArgumentException::class);

        app(PaymentService::class)->recordPayment([
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'amount'        => 10,
            'payment_date'  => '2025-09-20',
            'method'        => 'cash',
            'allocations'   => [
                ['student_fee_id' => $fee->id, 'amount' => 10],
            ],
        ]);
    }

    public function test_a_cancelled_waiver_is_not_counted_twice(): void
    {
        $fee = $this->makeFee(100);
        $service = app(FeeWaiverService::class);

        $first = $service->waive($fee, 40, 'تنازل أول');
        $service->waive($fee, 30, 'تنازل ثان');
        $this->assertSame(30.0, $fee->fresh()->outstanding());

        $service->cancel($first, 'إلغاء الأول');

        $this->assertSame(70.0, $fee->fresh()->outstanding());
        $this->assertSame(1, FeeWaiver::whereNull('cancelled_at')->count());
    }
}
