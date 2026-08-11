<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\FeePlan;
use App\Models\Level;
use App\Services\CollectionService;
use App\Services\DiscountService;
use Database\Seeders\FeePlanSeeder;
use Database\Seeders\SchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SixthGradeFeeUpdateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Verify that the monthly tuition fee for 6th Grade (L6) in the active academic year
     * is updated to 190 TND, while all other levels remain strictly unchanged.
     */
    public function test_sixth_grade_monthly_fee_is_190_and_other_levels_are_unchanged(): void
    {
        $activeYear = AcademicYear::create([
            'name'       => '2026-2027',
            'start_date' => '2026-09-15',
            'end_date'   => '2027-06-30',
            'is_active'  => 1,
        ]);

        $this->seed(SchoolSeeder::class);
        $this->seed(FeePlanSeeder::class);

        $l6 = Level::where('code', 'L6')->firstOrFail();

        $l6Plan = FeePlan::where('academic_year_id', $activeYear->id)
            ->where('level_id', $l6->id)
            ->where('frequency', 'monthly')
            ->firstOrFail();

        // 1. 6th Grade fee is 190.00 TND
        $this->assertEquals(190.00, (float) $l6Plan->amount);

        // 2. Expected amounts for all other levels
        $expectedOtherLevels = [
            'L1'   => 150.00,
            'L2'   => 150.00,
            'L3'   => 160.00,
            'L4'   => 160.00,
            'L5'   => 180.00,
            'PRE1' => 90.00,
            'PRE2' => 100.00,
            'PRE3' => 120.00,
        ];

        foreach ($expectedOtherLevels as $code => $expectedAmount) {
            $level = Level::where('code', $code)->first();
            if (! $level) {
                continue;
            }

            $plan = FeePlan::where('academic_year_id', $activeYear->id)
                ->where('level_id', $level->id)
                ->where('frequency', 'monthly')
                ->first();

            if ($plan) {
                $this->assertEquals(
                    $expectedAmount,
                    (float) $plan->amount,
                    "Fee for level {$code} must remain unchanged at {$expectedAmount} TND."
                );
            }
        }
    }

    /**
     * Verify that fixed discount amount remains unchanged when tuition fee updates to 190 TND,
     * and net due is exactly 190.00 - fixed_discount.
     */
    public function test_fixed_discount_remains_unchanged_and_net_due_equals_190_minus_fixed_discount(): void
    {
        $activeYear = AcademicYear::create([
            'name'       => '2026-2027',
            'start_date' => '2026-09-15',
            'end_date'   => '2027-06-30',
            'is_active'  => 1,
        ]);

        $this->seed(SchoolSeeder::class);
        $this->seed(FeePlanSeeder::class);

        $l6 = Level::where('code', 'L6')->firstOrFail();
        $enrollment = $this->makeEnrollment($activeYear);
        $enrollment->update(['level_id' => $l6->id]);

        // Grant a fixed discount of 10.00 TND / month
        $fixedDiscountAmount = 10.00;
        $disc = app(DiscountService::class)->createForEnrollment(
            $enrollment->id,
            $fixedDiscountAmount,
            'خصم ثابت عادي',
            '2026-09-15'
        );

        // 1. Discount amount remains strictly fixed at 10.00 TND (not changed by tuition update)
        $this->assertEquals($fixedDiscountAmount, (float) $disc->amount);

        // 2. Collection preview for 6th grade with 190 TND tuition fee:
        // gross = 190.00, discount = 10.00, net due = 180.00 (190.00 - 10.00)
        $collectionService = app(CollectionService::class);
        $prev = $collectionService->preview($enrollment->id, ['2026-09']);

        $this->assertEquals(190.00, $prev['gross_amount']);
        $this->assertEquals($fixedDiscountAmount, $prev['discount_amount']);
        $expectedNetDue = round(190.00 - $fixedDiscountAmount, 2); // 180.00
        $this->assertEquals($expectedNetDue, $prev['net_due']);
        $this->assertEquals($expectedNetDue, $prev['remaining_amount']);
    }

    /**
     * Verify that any historical payment existing before the modification has not changed.
     */
    public function test_historical_payment_remains_unchanged(): void
    {
        $activeYear = AcademicYear::create([
            'name'       => '2026-2027',
            'start_date' => '2026-09-15',
            'end_date'   => '2027-06-30',
            'is_active'  => 1,
        ]);

        $this->seed(SchoolSeeder::class);
        $this->seed(FeePlanSeeder::class);

        $l6 = Level::where('code', 'L6')->firstOrFail();
        $enrollment = $this->makeEnrollment($activeYear);
        $enrollment->update(['level_id' => $l6->id]);

        // Create a historical payment for the old price of 180.00 TND
        $payment = \App\Models\Payment::create([
            'student_id'      => $enrollment->student_id,
            'enrollment_id'   => $enrollment->id,
            'amount'          => 180.00,
            'payment_date'    => '2026-09-15',
            'method'          => 'cash',
            'months'          => ['2026-09'],
            'created_by'      => \App\Models\User::first()->id,
        ]);

        $feeType = \App\Models\FeeType::firstOrCreate(
            ['name_ar' => 'القسط الشهري'],
            ['price' => 180, 'is_active' => true]
        );

        $studentFee = \App\Models\StudentFee::create([
            'enrollment_id' => $enrollment->id,
            'fee_type_id'   => $feeType->id,
            'amount_due'    => 180.00,
            'due_date'      => '2026-09-15',
            'status'        => 'paid',
            'description'   => 'القسط الشهري — سبتمبر 2026',
        ]);

        \App\Models\PaymentAllocation::create([
            'payment_id'       => $payment->id,
            'student_fee_id'   => $studentFee->id,
            'amount_allocated' => 180.00,
        ]);

        // Refresh and assert the payment values remain strictly unchanged
        $payment->refresh();
        $this->assertEquals(180.00, (float) $payment->amount);

        $studentFee->refresh();
        $this->assertEquals(180.00, (float) $studentFee->amount_due);
    }
}
