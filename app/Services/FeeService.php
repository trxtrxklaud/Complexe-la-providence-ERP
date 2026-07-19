<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\StudentFee;
use Carbon\Carbon;

class FeeService
{
    /**
     * Generate monthly/quarterly student fees for an enrollment
     * based on active FeePlans for the level and academic year.
     */
    public function generateFeesForEnrollment(Enrollment $enrollment): void
    {
        $feePlans = FeePlan::where('academic_year_id', $enrollment->academic_year_id)
            ->where('level_id', $enrollment->level_id)
            ->get();

        foreach ($feePlans as $plan) {
            if ($plan->frequency === 'monthly') {
                $this->generateMonthlyFees($enrollment, $plan);
            } elseif ($plan->frequency === 'yearly') {
                StudentFee::create([
                    'enrollment_id' => $enrollment->id,
                    'fee_plan_id'   => $plan->id,
                    'description'   => $plan->name,
                    'amount_due'    => $plan->amount,
                    'due_date'      => $enrollment->academicYear->start_date,
                    'status'        => 'pending',
                ]);
            }
            // Add quarterly logic if needed
        }
    }

    private function generateMonthlyFees(Enrollment $enrollment, FeePlan $plan): void
    {
        $startDate = Carbon::parse($enrollment->academicYear->start_date);
        $endDate   = Carbon::parse($enrollment->academicYear->end_date);

        $current = $startDate->copy()->day($plan->due_day ?? 1);

        while ($current->lte($endDate)) {
            StudentFee::firstOrCreate(
                [
                    'enrollment_id' => $enrollment->id,
                    'fee_plan_id'   => $plan->id,
                    'due_date'      => $current->toDateString(),
                ],
                [
                    'description' => $plan->name . ' - ' . $current->format('F Y'),
                    'amount_due'  => $plan->amount,
                    'status'      => 'pending',
                ]
            );

            $current->addMonth();
        }
    }
}
