<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
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

        // الجرد النقدي لا يتبع سنة دراسية: المدرسة تستخلص في كل الأشهر،
        // ودفعة أوت لمتخلَّد جوان حركة نقدية يوم أوت مهما كانت السنة الدراسية
        // التي تخصّها. لذلك تُحسب الكروت النقدية قبل التحقّق من السنة النشطة
        // ولا تتوقّف عليها إطلاقاً: صاحبة المدرسة ترى حركة اليوم حتّى في العطلة.
        $cash = [
            'today'    => $this->cashFigures($today->toDateString(), $today->toDateString()),
            'month'    => $this->cashFigures($today->copy()->startOfMonth()->toDateString(), $today->toDateString()),
            'all_time' => $this->cashFigures(null, $today->toDateString()),
        ];

        if (!$activeYear) {
            return $this->emptyDashboard($cash);
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
            ->selectRaw('COALESCE(SUM(CASE WHEN student_fees.amount_due - COALESCE(pa.total_allocated, 0) > 0 THEN student_fees.amount_due - COALESCE(pa.total_allocated, 0) ELSE 0 END), 0) AS balance')
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
            // الجرد النقدي المحيّن: اليوم، الشهر الجاري، ومن بداية السجلّ.
            'cash'                   => $cash,
            'treasury_balance'       => $cash['all_time']['balance'],
        ];
    }

    /**
     * أرقام الصندوق لفترة، مقروءة من الدفتر النقدي المركزي حصراً.
     *
     * تُقرأ من نفس الجدول الذي تقرأ منه شاشات الخزينة والدخل الصافي،
     * فيستحيل أن تعرض اللوحة رقماً يخالف الكشف. والسحب لا يُنقِص
     * الدخل الصافي لأنّه نقل أموال لا استهلاك، لكنّه يُنقِص الرصيد.
     *
     * @return array<string,float>
     */
    private function cashFigures(?string $from, string $to): array
    {
        $base = CashTransaction::query()
            ->whereNull('cancelled_at')
            ->when($from !== null, fn ($q) => $q->whereDate('transaction_date', '>=', $from))
            ->whereDate('transaction_date', '<=', $to);

        $income = (float) (clone $base)
            ->whereIn('category', CashTransaction::INCOME_CATEGORIES)
            ->sum('amount');

        $expenses = (float) (clone $base)
            ->whereIn('category', CashTransaction::EXPENSE_CATEGORIES)
            ->sum('amount');

        $withdrawals = (float) (clone $base)
            ->where('category', CashTransaction::CATEGORY_WITHDRAWAL)
            ->sum('amount');

        return [
            'income'      => round($income, 2),
            'expenses'    => round($expenses, 2),
            'net_income'  => round($income - $expenses, 2),
            'withdrawals' => round($withdrawals, 2),
            'balance'     => round($income - $expenses - $withdrawals, 2),
        ];
    }

    /**
     * @param  array<string,array<string,float>>  $cash
     */
    private function emptyDashboard(array $cash): array
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
            // حتّى بلا سنة نشطة، حركة الصندوق تبقى ظاهرة.
            'cash'                   => $cash,
            'treasury_balance'       => $cash['all_time']['balance'],
        ];
    }
}
