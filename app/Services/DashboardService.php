<?php

namespace App\Services;

use App\Models\Student;
use App\Models\Enrollment;
use App\Models\AcademicYear;
use App\Models\StudentFee;
use Carbon\Carbon;

class DashboardService
{
    public function getDashboardData(): array
    {
        $today = Carbon::today();
        $activeYear = AcademicYear::where('is_active', true)->first();

        if (!$activeYear) {
            return $this->emptyDashboard();
        }

        $totalStudents = Enrollment::where('academic_year_id', $activeYear->id)
            ->where('status', 'active')
            ->count();

        $newEnrollments = Enrollment::where('academic_year_id', $activeYear->id)
            ->whereDate('enrollment_date', '>=', $activeYear->start_date)
            ->count();

        $males = Student::whereHas('enrollments', function ($q) use ($activeYear) {
            $q->where('academic_year_id', $activeYear->id)
              ->where('status', 'active');
        })->where('gender', 'male')->count();

        $females = Student::whereHas('enrollments', function ($q) use ($activeYear) {
            $q->where('academic_year_id', $activeYear->id)
              ->where('status', 'active');
        })->where('gender', 'female')->count();

        // Outstanding balance
        $outstandingBalance = StudentFee::whereHas('enrollment', function ($q) use ($activeYear) {
            $q->where('academic_year_id', $activeYear->id)
              ->where('status', 'active');
        })
        ->whereIn('status', ['pending', 'partial', 'overdue'])
        ->get()
        ->sum(function ($fee) {
            $allocated = $fee->paymentAllocations()->sum('amount_allocated');
            return max(0, $fee->amount_due - $allocated);
        });

        return [
            'current_date'           => $today->toDateString(),
            'academic_year'          => $activeYear,
            'total_students'         => $totalStudents,
            'new_students_this_year' => $newEnrollments,
            'total_males'            => $males,
            'total_females'          => $females,
            'outstanding_balance'    => $outstandingBalance,
            'upcoming_events'        => [], // Can be connected to CalendarEvent later
            'financial_summary'      => [
                'total_revenue'   => 0, // Can be calculated from payments
                'collected_amount' => 0,
                'pending_amount'  => $outstandingBalance,
            ],
        ];
    }

    private function emptyDashboard(): array
    {
        return [
            'current_date' => now()->toDateString(),
            'academic_year' => null,
            'total_students' => 0,
            'new_students_this_year' => 0,
            'total_males' => 0,
            'total_females' => 0,
            'outstanding_balance' => 0,
            'financial_summary' => [
                'total_revenue' => 0,
                'collected_amount' => 0,
                'pending_amount' => 0,
            ],
        ];
    }
}
