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
    public function getDashboardData(bool $includeFinancials = true): array
    {
        $today      = Carbon::today();
        $activeYear = AcademicYear::where('is_active', true)->first();

        // الجرد النقدي لا يتبع سنة دراسية: المدرسة تستخلص في كل الأشهر،
        // ودفعة أوت لمتخلَّد جوان حركة نقدية يوم أوت مهما كانت السنة الدراسية
        // التي تخصّها. لذلك تُحسب الكروت النقدية قبل التحقّق من السنة النشطة
        // ولا تتوقّف عليها إطلاقاً: صاحبة المدرسة ترى حركة اليوم حتّى في العطلة.
        // الجرد النقدي (رصيد الخزينة، المصاريف، السحوبات، الدخل الصافي) حِكرٌ على
        // من يملك manage_treasury أو view_reports. القابض يستخلص المال ولا يرى
        // وضع الخزينة، فلا تُحسب الأرقام النقدية أصلاً حين لا يُسمح بعرضها.
        $cash = $includeFinancials ? [
            'today'    => $this->cashFigures($today->toDateString(), $today->toDateString()),
            'month'    => $this->cashFigures($today->copy()->startOfMonth()->toDateString(), $today->toDateString()),
            'all_time' => $this->cashFigures(null, $today->toDateString()),
        ] : null;

        if (!$activeYear) {
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

        foreach ($activeStudents as $student) {
            $g = $student->gender ? strtolower(trim((string) $student->gender)) : $this->inferGenderFromName((string) $student->first_name);
            if (in_array($g, ['male', 'm', 'ذكر'], true)) {
                $malesCount++;
            } elseif (in_array($g, ['female', 'f', 'أنثى'], true)) {
                $femalesCount++;
            } else {
                $unknownCount++;
            }
        }

        $totalStudents = $activeStudents->count();

        $newEnrollments = Enrollment::where('academic_year_id', $activeYear->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereDate('enrollment_date', '>=', $activeYear->start_date)
            ->distinct('student_id')
            ->count('student_id');

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

        $data = [
            'current_date'           => $today->toDateString(),
            'academic_year'          => $activeYear,
            'total_students'         => $totalStudents,
            'total_active_students'  => $totalStudents,
            'new_students_this_year' => $newEnrollments,
            'total_males'            => $malesCount,
            'male_students_count'    => $malesCount,
            'total_females'          => $femalesCount,
            'female_students_count'  => $femalesCount,
            'total_unspecified_gender' => $unknownCount,
            'unknown_gender_count'   => $unknownCount,
            'outstanding_balance'    => $outstandingBalance,
            'upcoming_events'        => [],
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
            $data['financial_summary'] = [
                'total_expected'   => $totalExpected,
                'collected_amount' => $totalCollected,
                'pending_amount'   => $outstandingBalance,
            ];
            $data['club_revenue'] = [
                'collected_amount'       => round($clubCollected, 2),
                'remaining_amount'       => round($clubRemaining, 2),
                'paid_students_count'    => $clubPaidCount,
                'pending_students_count' => $clubPendingCount,
            ];
            // الجرد النقدي المحيّن: اليوم، الشهر الجاري، ومن بداية السجلّ.
            $data['cash']             = $cash;
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
     * @param  array<string,array<string,float>>|null  $cash
     */
    private function emptyDashboard(?array $cash, bool $includeFinancials = true): array
    {
        $data = [
            'current_date'           => now()->toDateString(),
            'academic_year'          => null,
            'total_students'         => 0,
            'total_active_students'  => 0,
            'new_students_this_year' => 0,
            'total_males'            => 0,
            'male_students_count'    => 0,
            'total_females'          => 0,
            'female_students_count'  => 0,
            'total_unspecified_gender' => 0,
            'unknown_gender_count'   => 0,
            'outstanding_balance'    => 0,
            'upcoming_events'        => [],
        ];

        if ($includeFinancials) {
            $data['financial_summary'] = [
                'total_expected'   => 0,
                'collected_amount' => 0,
                'pending_amount'   => 0,
            ];
            // حتّى بلا سنة نشطة، حركة الصندوق تبقى ظاهرة لمن يملك رؤيتها.
            $data['cash']             = $cash;
            $data['treasury_balance'] = $cash['all_time']['balance'];
        }

        return $data;
    }

    /**
     * الاستدلال من الاسم العربي للتلاميذ المستوردين الذين لم يُسجّل جنسهم في قاعدة البيانات.
     */
    private function inferGenderFromName(string $firstName): ?string
    {
        $name = trim($firstName);
        if ($name === '') {
            return null;
        }

        $parts = preg_split('/\s+/u', $name);
        $first = $parts[0] ?? '';
        $normalizedFirst = str_replace(['أ', 'إ', 'آ'], 'ا', $first);

        $knownFemales = [
            'امنة', 'اية', 'ميار', 'سيرين', 'ريحان', 'مريم', 'ريم', 'سارة', 'ساره', 'لينا', 'ميرال',
            'نور', 'هبة', 'ياسمين', 'سلمى', 'خديجة', 'فاطمة', 'عائشة', 'زينب', 'نادين', 'شهد',
            'جنى', 'جودي', 'رتاج', 'ريتاج', 'تالين', 'اريج', 'اسراء', 'ايلاف', 'بلقيس', 'تسنيم',
            'حنين', 'داليا', 'دانية', 'رغد', 'روضة', 'زينة', 'سمر', 'سندس', 'شذى', 'شيماء',
            'عبير', 'غادة', 'غفران', 'فرح', 'لمى', 'مارية', 'مروى', 'ملاك', 'منال', 'مها',
            'ناديا', 'ندى', 'نغم', 'نهى', 'هاجر', 'وئام', 'يسر', 'رنيم', 'اميمة', 'الاء', 'اسماء',
            'ايناس', 'احلام', 'امال', 'اماني', 'اميرة', 'انسام', 'انصاف', 'انعام', 'ايمان',
            'بتول', 'بشرى', 'بسمة', 'تقوى', 'جواهر', 'جيهان', 'حسناء', 'حورية', 'خلود',
            'دعا', 'دعاء', 'ذكرى', 'رحمة', 'رحاب', 'رضوى', 'رنا', 'رندة', 'رهف', 'روان', 'رولا',
            'زهراء', 'زهرة', 'سلاف', 'سهام', 'سهيلة', 'سوزان', 'سناء', 'شروق', 'صفاء',
            'ضحى', 'عفاف', 'علا', 'علياء', 'غزلان', 'فاتن', 'فدوى', 'فيروز', 'كوثر', 'لمياء',
            'ليندا', 'ماجدة', 'مرام', 'مروة', 'منى', 'منيرة', 'مي', 'ميادة', 'ميساء', 'ميسون',
            'نجلاء', 'نجوى', 'نوال', 'نورها', 'نورهان', 'هالة', 'هناء', 'هنادي', 'هند', 'وفاء',
            'ولا', 'ولاء', 'يسرى', 'فردوس',
        ];

        $knownMales = [
            'خالد', 'اياد', 'ماجد', 'احمد', 'محمد', 'يوسف', 'امين', 'علي', 'عمر', 'حمزة',
            'بلال', 'انس', 'ريان', 'مهدي', 'وسيم', 'ادم', 'سليم', 'ياسين', 'عزيز', 'خليل',
            'فادي', 'كريم', 'هادي', 'الهادي', 'مالك', 'هارون', 'مصطفى', 'طه', 'وائل', 'زياد',
            'وليد', 'رامي', 'سامي', 'غسان', 'عمار', 'لؤي', 'اسامة', 'شريف', 'فريد', 'منتصر',
            'نضال', 'صابر', 'ضياء', 'عبد', 'سيف', 'فراس', 'ابراهيم', 'اسماعيل', 'ايمن', 'انور',
            'اشرف', 'ايوب', 'بدر', 'باسم', 'بشير', 'تامر', 'توفيق', 'جاسم', 'جابر', 'جلال',
            'جمال', 'حسام', 'حسان', 'حسن', 'حسين', 'حلمي', 'حمد', 'حمدي', 'حيدر',
            'داود', 'ربيع', 'رجب', 'رشيد', 'رضا', 'رمزي', 'زيان', 'سعد', 'سعود', 'سعيد',
            'سفيان', 'سلمان', 'سليمان', 'سمير', 'شادي', 'صالح', 'صلاح', 'طارق', 'عادل',
            'عارف', 'عاصم', 'عاطف', 'عباس', 'عبدالله', 'عبدالرحمن', 'عبدالعزيز', 'عبدالمجيد',
            'عثمان', 'عصام', 'علاء', 'عماد', 'فارس', 'فارق', 'فاروق', 'فاضل', 'فؤاد',
            'فوزي', 'فيصل', 'قصي', 'قيس', 'مازن', 'ماهر', 'مجدي', 'محمود', 'مروان',
            'مزهر', 'مسعود', 'معاذ', 'مقداد', 'منير', 'مهند', 'موسى', 'موفق', 'ناجي',
            'نايف', 'نبيل', 'نجيب', 'نزار', 'نوح', 'نورالدين', 'هاشم', 'هشام', 'هيثم',
            'وجدي', 'وديع', 'وسام', 'ياسر', 'يحيى', 'يعقوب', 'يونس',
        ];

        foreach ($knownFemales as $kf) {
            $normalizedKf = str_replace(['أ', 'إ', 'آ'], 'ا', $kf);
            if ($normalizedFirst === $normalizedKf) {
                return 'female';
            }
        }

        foreach ($knownMales as $km) {
            $normalizedKm = str_replace(['أ', 'إ', 'آ'], 'ا', $km);
            if ($normalizedFirst === $normalizedKm) {
                return 'male';
            }
        }

        if (str_ends_with($first, 'ة') || str_ends_with($first, 'اء')) {
            return 'female';
        }

        return null;
    }
}
