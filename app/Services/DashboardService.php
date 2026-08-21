<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function getDashboardData(bool $includeFinancials = true): array
    {
        $today = Carbon::today();
        $activeYear = AcademicYear::where('is_active', true)->first();

        // الجرد النقدي لا يتبع سنة دراسية: المدرسة تستخلص في كل الأشهر،
        // ودفعة أوت لمتخلَّد جوان حركة نقدية يوم أوت مهما كانت السنة الدراسية
        // التي تخصّها. لذلك تُحسب الكروت النقدية قبل التحقّق من السنة النشطة
        // ولا تتوقّف عليها إطلاقاً: صاحبة المدرسة ترى حركة اليوم حتّى في العطلة.
        // الجرد النقدي (رصيد الخزينة، المصاريف، السحوبات، الدخل الصافي) حِكرٌ على
        // من يملك manage_treasury أو view_reports. القابض يستخلص المال ولا يرى
        // وضع الخزينة، فلا تُحسب الأرقام النقدية أصلاً حين لا يُسمح بعرضها.
        $cash = $includeFinancials ? [
            'today' => $this->cashFigures($today->toDateString(), $today->toDateString()),
            'month' => $this->cashFigures($today->copy()->startOfMonth()->toDateString(), $today->toDateString()),
            'all_time' => $this->cashFigures(null, $today->toDateString()),
        ] : null;

        if (! $activeYear) {
            return $this->emptyDashboard($cash, $includeFinancials);
        }

        $activeStudents = DB::table('enrollments')
            ->join('students', 'enrollments.student_id', '=', 'students.id')
            ->where('enrollments.academic_year_id', $activeYear->id)
            ->where('enrollments.status', 'active')
            ->whereNull('enrollments.deleted_at')
            ->select('students.id', 'students.first_name', 'students.gender')
            ->get()
            ->unique('id');

        $malesCount = 0;
        $femalesCount = 0;
        $unknownCount = 0;

        $studentService = app(StudentService::class);
        $inferGenderFromName = new \ReflectionMethod(StudentService::class, 'inferGenderFromName');
        $inferGenderFromName->setAccessible(true);

        foreach ($activeStudents as $student) {
            $g = strtolower(trim((string) $student->gender));
            if (in_array($g, ['male', 'm', 'ذكر'], true)) {
                $malesCount++;
            } elseif (in_array($g, ['female', 'f', 'أنثى'], true)) {
                $femalesCount++;
            } else {
                $inferredGender = $inferGenderFromName->invoke($studentService, $student->first_name);
                if ($inferredGender === 'male') {
                    $malesCount++;
                } elseif ($inferredGender === 'female') {
                    $femalesCount++;
                } else {
                    $unknownCount++;
                }
            }
        }

        $totalStudents = $malesCount + $femalesCount + $unknownCount;

        $newEnrollments = Enrollment::where('academic_year_id', $activeYear->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereDate('enrollment_date', '>=', $activeYear->start_date)
            ->distinct('student_id')
            ->count('student_id');

        $outstandingBalance = (float) DB::table('student_fees')
            ->join('enrollments', 'student_fees.enrollment_id', '=', 'enrollments.id')
            ->leftJoin(
                // فقط توزيعات الدفعات غير الملغاة: إلغاء دفعة يعيد المتبقّي كما كان.
                DB::raw('(SELECT pa2.student_fee_id, SUM(pa2.amount_allocated) AS total_allocated
                          FROM payment_allocations pa2
                          INNER JOIN payments p2 ON p2.id = pa2.payment_id
                          WHERE p2.cancelled_at IS NULL
                          GROUP BY pa2.student_fee_id) AS pa'),
                'pa.student_fee_id', '=', 'student_fees.id'
            )
            ->where('enrollments.academic_year_id', $activeYear->id)
            ->where('enrollments.status', 'active')
            ->whereNull('enrollments.deleted_at')
            ->whereIn('student_fees.status', ['pending', 'partial', 'overdue'])
            // القاعدة الذهبية: المتخلد = شهر حان استحقاقه ولم يُدفع؛ المستقبل ليس ديناً.
            ->whereDate('student_fees.due_date', '<=', now())
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

        $data = [
            'current_date' => $today->toDateString(),
            'academic_year' => $activeYear,
            'total_students' => $totalStudents,
            'total_active_students' => $totalStudents,
            'new_students_this_year' => $newEnrollments,
            'total_males' => $malesCount,
            'male_students_count' => $malesCount,
            'total_females' => $femalesCount,
            'female_students_count' => $femalesCount,
            'total_unspecified_gender' => $unknownCount,
            'unknown_gender_count' => $unknownCount,
            'outstanding_balance' => $outstandingBalance,
            'upcoming_events' => [],
        ];

        $currentMonth = $today->format('Y-m');
        $currentMonthStart = $today->copy()->startOfMonth()->toDateString();

        $clubCollected = (float) CashTransaction::whereNull('cancelled_at')
            ->where('category', CashTransaction::CATEGORY_CLUB_FEE)
            ->whereDate('transaction_date', '>=', $currentMonthStart)
            ->whereDate('transaction_date', '<=', $today->toDateString())
            ->sum('amount');

        $clubMonthlyQuery = DB::table('club_monthly_fees')
            ->whereNull('cancelled_at')
            ->where('month', $currentMonth)
            ->where('academic_year_id', $activeYear->id);

        $clubRemaining = (float) (clone $clubMonthlyQuery)
            ->selectRaw('COALESCE(SUM(CASE WHEN amount_due - amount_paid > 0 THEN amount_due - amount_paid ELSE 0 END), 0) AS remaining')
            ->value('remaining') ?? 0;

        $clubPaidCount = (int) (clone $clubMonthlyQuery)
            ->where('status', 'paid')
            ->count();

        $clubPendingCount = (int) (clone $clubMonthlyQuery)
            ->whereIn('status', ['unpaid', 'partial', 'pending'])
            ->count();

        if ($includeFinancials) {
            // متخلّدات السنوات السابقة المنقولة إلى السنة النشطة (رصيد افتتاحي).
            $priorYearOutstanding = (float) DB::table('opening_balances')
                ->join('enrollments', 'opening_balances.source_enrollment_id', '=', 'enrollments.id')
                ->leftJoin(
                    // فقط توزيعات الدفعات غير الملغاة: إلغاء دفعة يعيد المتخلّد القديم كما كان.
                    DB::raw('(SELECT pa2.student_fee_id, SUM(pa2.amount_allocated) AS total_allocated
                              FROM payment_allocations pa2
                              INNER JOIN payments p2 ON p2.id = pa2.payment_id
                              WHERE p2.cancelled_at IS NULL
                              GROUP BY pa2.student_fee_id) AS pa'),
                    'pa.student_fee_id', '=', 'opening_balances.source_student_fee_id'
                )
                ->where('opening_balances.academic_year_id', $activeYear->id)
                ->whereNull('opening_balances.cancelled_at')
                ->selectRaw('COALESCE(SUM(CASE WHEN opening_balances.amount - COALESCE(pa.total_allocated, 0) > 0 THEN opening_balances.amount - COALESCE(pa.total_allocated, 0) ELSE 0 END), 0) AS balance')
                ->value('balance') ?? 0;

            $data['financial_summary'] = [
                'total_expected' => $totalExpected,
                'collected_amount' => $totalCollected,
                'pending_amount' => $outstandingBalance,
                // تفصيل المتخلّد: ما هو من السنة الحالية وما هو منقولة من سابقاتها.
                'current_year_outstanding' => round($outstandingBalance, 2),
                'prior_year_outstanding' => round((float) $priorYearOutstanding, 2),
            ];
            $data['club_revenue'] = [
                'collected_amount' => round($clubCollected, 2),
                'remaining_amount' => round($clubRemaining, 2),
                'paid_students_count' => $clubPaidCount,
                'pending_students_count' => $clubPendingCount,
            ];
            // الجرد النقدي المحيّن: اليوم، الشهر الجاري، ومن بداية السجلّ.
            $data['cash'] = $cash;
            $data['treasury_balance'] = $cash['all_time']['balance'];
        }

        return $data;
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

        $priorYearDebt = (float) (clone $base)
            ->whereIn('category', CashTransaction::PRIOR_YEAR_DEBT_CATEGORIES)
            ->sum('amount');

        $expenses = (float) (clone $base)
            ->whereIn('category', CashTransaction::EXPENSE_CATEGORIES)
            ->sum('amount');

        $withdrawals = (float) (clone $base)
            ->where('category', CashTransaction::CATEGORY_WITHDRAWAL)
            ->sum('amount');

        return [
            'income' => round($income, 2),
            // تحصيل ديون السنوات السابقة: نقد دخل الصندوق، لكنه ليس مدخولاً —
            // يزيد الرصيد ولا يدخل في الدخل الصافي.
            'prior_year_debt' => round($priorYearDebt, 2),
            'expenses' => round($expenses, 2),
            'net_income' => round($income - $expenses, 2),
            'withdrawals' => round($withdrawals, 2),
            'balance' => round($income + $priorYearDebt - $expenses - $withdrawals, 2),
        ];
    }

    /**
     * @param  array<string,array<string,float>>|null  $cash
     */
    private function emptyDashboard(?array $cash, bool $includeFinancials = true): array
    {
        $data = [
            'current_date' => now()->toDateString(),
            'academic_year' => null,
            'total_students' => 0,
            'total_active_students' => 0,
            'new_students_this_year' => 0,
            'total_males' => 0,
            'male_students_count' => 0,
            'total_females' => 0,
            'female_students_count' => 0,
            'total_unspecified_gender' => 0,
            'unknown_gender_count' => 0,
            'outstanding_balance' => 0,
            'upcoming_events' => [],
        ];

        if ($includeFinancials) {
            $data['financial_summary'] = [
                'total_expected' => 0,
                'collected_amount' => 0,
                'pending_amount' => 0,
                'current_year_outstanding' => 0,
                'prior_year_outstanding' => 0,
            ];
            // حتّى بلا سنة نشطة، حركة الصندوق تبقى ظاهرة لمن يملك رؤيتها.
            $data['cash'] = $cash;
            $data['treasury_balance'] = $cash['all_time']['balance'];
        }

        return $data;
    }
}
