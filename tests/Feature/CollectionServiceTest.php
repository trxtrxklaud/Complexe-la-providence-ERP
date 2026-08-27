<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubMonthlyFee;
use App\Models\FeeCategory;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Student;
use App\Models\StudentFee;
use App\Services\ClubService;
use App\Services\CollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private CollectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CollectionService::class);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'months'       => ['2025-09'],
            'payment_date' => '2025-09-05',
            'method'       => 'cash',
            'discount'     => 0,
        ], $overrides);
    }

    public function test_collect_creates_payment_fee_and_allocation(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $enrollment = $this->makeEnrollment();
        $feeType = $this->makeFeeType();

        $receipt = $this->service->collect($this->payload([
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'items'         => [['fee_type_id' => $feeType->id, 'amount' => 240]],
        ]), $user->id);

        $this->assertSame(240.0, (float) $receipt['total']);
        $this->assertSame('سبتمبر 2025', $receipt['months_label']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('student_fees', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertSame(240.0, (float) Payment::first()->amount);
    }

    public function test_fee_status_is_derived_from_allocations_not_hardcoded(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $enrollment = $this->makeEnrollment();
        $feeType = $this->makeFeeType();

        $this->service->collect($this->payload([
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'items'         => [['fee_type_id' => $feeType->id, 'amount' => 240]],
        ]), $user->id);

        $fee = StudentFee::first();
        $allocated = (float) $fee->paymentAllocations()->sum('amount_allocated');

        $this->assertSame('paid', $fee->status);
        $this->assertSame((float) $fee->amount_due, $allocated);
    }

    public function test_full_price_is_stored_and_transactional_discount_is_ignored(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $enrollment = $this->makeEnrollment();
        $tuition = $this->makeFeeType('القسط الشهري', 240);
        $transport = $this->makeFeeType('نقل مدرسي', 60);

        // حتى لو أرسلت الواجهة القديمة حقل discount، يُتجاهل: التخفيض صار سنوياً
        // ثابتاً في enrollment_discounts، والرسوم تُخزَّن بسعرها الكامل.
        $receipt = $this->service->collect($this->payload([
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'discount'      => 20,
            'items'         => [
                ['fee_type_id' => $tuition->id,   'amount' => 240],
                ['fee_type_id' => $transport->id, 'amount' => 60],
            ],
        ]), $user->id);

        $payment = Payment::first();
        $allocated = (float) PaymentAllocation::sum('amount_allocated');
        $due = (float) StudentFee::sum('amount_due');

        // السعر الكامل 300 لا 280: التخفيض المُرسل تُجووِز بلا أثر.
        $this->assertSame(300.0, (float) $receipt['total']);
        $this->assertSame(300.0, (float) $payment->amount);
        $this->assertSame(300.0, $allocated);
        $this->assertSame(300.0, $due);
        // الوصل يُبقي حقل التخفيض صفراً للتوافق.
        $this->assertSame(0.0, (float) $receipt['discount']);
        $this->assertSame(240.0, (float) $receipt['items'][0]['amount']);
        $this->assertSame(60.0, (float) $receipt['items'][1]['amount']);
    }

    public function test_rejects_enrollment_belonging_to_another_student(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $enrollment = $this->makeEnrollment();
        $other = Student::create([
            'student_code' => 'STU-OTHER',
            'first_name'   => 'سارة',
            'last_name'    => 'بن علي',
            'gender'       => 'female',
            'status'       => 'active',
        ]);
        $feeType = $this->makeFeeType();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->collect($this->payload([
            'student_id'    => $other->id,
            'enrollment_id' => $enrollment->id,
            'items'         => [['fee_type_id' => $feeType->id, 'amount' => 240]],
        ]), $user->id);
    }

    public function test_rejects_duplicate_month(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $enrollment = $this->makeEnrollment();
        $feeType = $this->makeFeeType();

        $base = $this->payload([
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'items'         => [['fee_type_id' => $feeType->id, 'amount' => 240]],
        ]);

        $this->service->collect($base, $user->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->collect($base, $user->id);
    }

    public function test_rejects_non_consecutive_months(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $enrollment = $this->makeEnrollment();
        $feeType = $this->makeFeeType();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->collect($this->payload([
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'months'        => ['2025-09', '2025-11'],
            'items'         => [['fee_type_id' => $feeType->id, 'amount' => 240]],
        ]), $user->id);
    }

    public function test_rejects_month_outside_academic_year(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $enrollment = $this->makeEnrollment();
        $feeType = $this->makeFeeType();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->collect($this->payload([
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'months'        => ['2026-07'],
            'items'         => [['fee_type_id' => $feeType->id, 'amount' => 240]],
        ]), $user->id);
    }

    public function test_rejects_skipping_first_unpaid_month(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $enrollment = $this->makeEnrollment();
        $feeType = $this->makeFeeType();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->collect($this->payload([
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'months'        => ['2025-10'],
            'items'         => [['fee_type_id' => $feeType->id, 'amount' => 240]],
        ]), $user->id);
    }

    public function test_failure_rolls_back_entire_transaction(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $enrollment = $this->makeEnrollment();
        $feeType = $this->makeFeeType();

        try {
            $this->service->collect($this->payload([
                'student_id'    => $enrollment->student_id,
                'enrollment_id' => $enrollment->id,
                'items'         => [
                    ['fee_type_id' => $feeType->id, 'amount' => 240],
                    ['fee_type_id' => 999999, 'amount' => 60], // غير موجود
                ],
            ]), $user->id);
            $this->fail('كان يجب رمي استثناء');
        } catch (\Throwable $e) {
            // متوقع
        }

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('student_fees', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_academic_year_months_are_ten_starting_september(): void
    {
        $year = $this->makeAcademicYear();
        $months = $this->service->getAcademicYearMonths($year);

        $this->assertCount(10, $months);
        $this->assertSame('2025-09', $months[0]);
        $this->assertSame('2026-06', $months[9]);
    }

    public function test_paid_months_accumulate_across_payments(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $enrollment = $this->makeEnrollment();
        $feeType = $this->makeFeeType();

        $this->service->collect($this->payload([
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'months'        => ['2025-09', '2025-10'],
            'items'         => [['fee_type_id' => $feeType->id, 'amount' => 480]],
        ]), $user->id);

        $this->service->collect($this->payload([
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'months'        => ['2025-11'],
            'items'         => [['fee_type_id' => $feeType->id, 'amount' => 240]],
        ]), $user->id);

        $paid = $this->service->getPaidMonths($enrollment->id);
        sort($paid);

        $this->assertSame(['2025-09', '2025-10', '2025-11'], $paid);
    }

    public function test_collecting_tuition_creates_unpaid_club_arrearage_and_can_collect_it_in_same_receipt(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $enrollment = $this->makeEnrollment();
        $tuition = $this->makeFeeType('القسط الشهري', 240);
        $category = FeeCategory::firstOrCreate(['code' => 'CLUB'], ['name' => 'معاليم النوادي', 'is_recurring' => true]);
        $club = Club::create([
            'name' => 'نادي الاختبار',
            'fee_category_id' => $category->id,
            'monthly_fee' => 50,
            'is_active' => true,
        ]);
        app(ClubService::class)->subscribeStudent($enrollment->student_id, $club->id, $enrollment->academic_year_id);

        $receipt = $this->service->collect($this->payload([
            'student_id' => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'items' => [['fee_type_id' => $tuition->id, 'amount' => 240]],
        ]), $user->id);

        $clubFee = ClubMonthlyFee::firstOrFail();
        $clubDebt = StudentFee::whereNotNull('club_monthly_fee_id')->firstOrFail();
        $this->assertSame(240.0, (float) $receipt['total']);
        $this->assertSame(0.0, (float) $clubFee->amount_paid);
        $this->assertSame(50.0, $clubDebt->outstanding());

        $second = $this->service->collect($this->payload([
            'months' => ['2025-10'],
            'student_id' => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'items' => [['fee_type_id' => $tuition->id, 'amount' => 240]],
            'club_items' => [['club_monthly_fee_id' => $clubFee->id, 'amount' => 50]],
        ]), $user->id);

        $clubFee->refresh();
        $this->assertSame(290.0, (float) $second['total']);
        $this->assertSame(50.0, (float) $clubFee->amount_paid);
        $this->assertSame(ClubMonthlyFee::STATUS_PAID, $clubFee->status);
        $this->assertSame(0.0, StudentFee::whereKey($clubDebt->id)->firstOrFail()->outstanding());
    }

    public function test_ledger_groups_payments_by_month(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $enrollment = $this->makeEnrollment();
        $feeType = $this->makeFeeType();

        $this->service->collect($this->payload([
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'months'        => ['2025-09', '2025-10'],
            'items'         => [['fee_type_id' => $feeType->id, 'amount' => 480]],
        ]), $user->id);

        $ledger = $this->service->monthLedger($enrollment->id);

        $this->assertArrayHasKey('2025-09', $ledger);
        $this->assertArrayHasKey('2025-10', $ledger);
        $this->assertCount(1, $ledger['2025-09']);
    }
}
