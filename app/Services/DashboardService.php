<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function getDashboardData(): array
    {
        $today      = Carbon::today();
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

        $genderCounts = Student::whereHas('enrollments', fn ($q) =>
            $q->where('academic_year_id', $activeYear->id)->where('status', 'active')
        )
        ->selectRaw('gender, COUNT(*) as count')
        ->groupBy('gender')
        ->pluck('count', 'gender');

        $outstandingBalance = (float) DB::table('student_fees')
            ->join('enrollments', 'student_fees.enrollment_id', '=', 'enrollments.id')
            ->leftJoin(
                DB::raw('(SELECT student_fee_id, SUM(amount_allocated) AS total_allocated
                          FROM payment_allocations GROUP BY student_fee_id) AS pa'),
                'pa.student_fee_id', '=', 'student_fees.id'
            )
            ->where('enrollments.academic_year_id', $activeYear->id)
            ->where('enrollments.status', 'active')
            ->whereNull('enrollments.deleted_at')
            ->whereIn('student_fees.status', ['pending', 'partial', 'overdue'])
            ->selectRaw('COALESCE(SUM(GREATEST(0, student_fees.amount_due - COALESCE(pa.total_allocated, 0))), 0) AS balance')
            ->value('balance') ?? 0;

        $totalCollected = (float) Payment::whereNotNull('enrollment_id')
            ->whereHas('enrollment', fn ($q) => $q->where('academic_year_id', $activeYear->id))
            ->sum('amount');

        $totalExpected = (float) DB::table('student_fees')
            ->join('enrollments', 'student_fees.enrollment_id', '=', 'enrollments.id')
            ->where('enrollments.academic_year_id', $activeYear->id)
            ->where('enrollments.status', 'active')
            ->whereNull('enrollments.deleted_at')
            ->sum('student_fees.amount_due');

        return [
            'current_date'           => $today->toDateString(),
            'academic_year'          => $activeYear,
            'total_students'         => $totalStudents,
            'new_students_this_year' => $newEnrollments,
            'total_males'            => (int) ($genderCounts['male']   ?? 0),
            'total_females'          => (int) ($genderCounts['female'] ?? 0),
            'outstanding_balance'    => $outstandingBalance,
            'upcoming_events'        => [],
            'financial_summary'      => [
                'total_expected'   => $totalExpected,
                'collected_amount' => $totalCollected,
                'pending_amount'   => $outstandingBalance,
            ],
        ];
    }

    private function emptyDashboard(): array
    {
        return [
            'current_date'           => now()->toDateString(),
            'academic_year'          => null,
            'total_students'         => 0,
            'new_students_this_year' => 0,
            'total_males'            => 0,
            'total_females'          => 0,
            'outstanding_balance'    => 0,
            'upcoming_events'        => [],
            'financial_summary'      => [
                'total_expected'   => 0,
                'collected_amount' => 0,
                'pending_amount'   => 0,
            ],
        ];
    }
}
