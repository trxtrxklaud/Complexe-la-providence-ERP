<?php

namespace App\Http\Controllers;

use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * التقارير المالية.
 *
 * قاعدة واحدة لا استثناء لها: كل مبلغ في هذه التقارير يُقرأ من cash_transactions
 * وليس من payments أو expenses أو salaries مباشرة. لو قرأت شاشتان من مصدرين
 * مختلفين لاختلفت الأرقام عند أول إلغاء أو تعديل. الجداول المصدرية تُستعمل
 * فقط للأبعاد الوصفية (أي تلميذ؟ أي قسم؟) لا للمبالغ.
 *
 * الأسطر الملغاة مستثناة دائماً (whereNull cancelled_at) فلا يلوّث أي مستند ملغى الأرقام.
 */
class FinancialReportController extends Controller
{
    /**
     * الدخل الصافي اليومي — مرآة للتقرير الورقي القديم: كشف يومي + تراكمي من بداية السجل
     * حتى التاريخ المختار، مع السحوبات والرصيد النهائي.
     */
    public function netIncome(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'details' => ['nullable', 'boolean'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
        ]);

        $date = $data['date'] ?? now()->toDateString();
        $yearId = $data['academic_year_id'] ?? null;

        $response = [
            'date' => $date,
            'day' => $this->summarize($date, $date, $yearId),
            'cumulative' => $this->summarize(null, $date, $yearId),
        ];

        if ($request->boolean('details')) {
            $response['details'] = $this->lines($date, $date, $yearId);
        }

        return response()->json($response);
    }

    /**
     * الدخل الصافي مجمّعاً شهرياً أو سنوياً.
     *
     * يستعمل عمداً نفس base() و linesFor() و periodExpression() التي يستعملها الكشف اليومي.
     * لو كتبتُ للشهري استعلاماً مستقلاً لأمكن أن ينحرف عن اليومي بعد أول تعديل في التصنيفات،
     * ومدير المدرسة يرى رقمين مختلفين لنفس الفترة دون أن يعرف أيهما الصحيح.
     */
    public function netIncomePeriods(Request $request): JsonResponse
    {
        $data = $request->validate([
            'granularity' => ['nullable', 'string', 'in:month,year'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
        ]);

        $granularity = $data['granularity'] ?? 'month';
        $from = $data['date_from'] ?? null;
        $to = $data['date_to'] ?? null;
        $yearId = $data['academic_year_id'] ?? null;

        $expression = $this->periodExpression($granularity);

        $rows = $this->base($from, $to, $yearId)
            ->selectRaw($expression.' as period')
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy(DB::raw($expression), 'category')
            ->orderBy('period')
            ->get();

        $periods = [];
        $grand = [];

        foreach ($rows as $row) {
            $total = round((float) $row->total, 2);

            $periods[$row->period][$row->category] = $total;
            $grand[$row->category] = round(($grand[$row->category] ?? 0.0) + $total, 2);
        }

        $result = [];
        foreach ($periods as $period => $totals) {
            $result[] = $this->netFigures((string) $period, $totals);
        }

        return response()->json([
            'granularity' => $granularity,
            'date_from' => $from,
            'date_to' => $to,
            'rows' => $result,
            'summary' => $this->netFigures('المجموع', $grand),
        ]);
    }

    /**
     * المداخيل حسب التاريخ: سطر لكل يوم مفصّل على بنود المداخيل.
     */
    public function incomeByDate(Request $request): JsonResponse
    {
        $data = $this->validatePeriod($request);

        return response()->json($this->groupedByPeriod(
            granularity: $request->input('granularity', 'day'),
            categories: CashTransaction::INCOME_CATEGORIES,
            from: $data['date_from'] ?? null,
            to: $data['date_to'] ?? null,
            yearId: $data['academic_year_id'] ?? null,
        ));
    }

    /**
     * المصاريف دورياً: يومياً أو شهرياً أو سنوياً — نفس المنطق وتتغير درجة التجميع فقط،
     * فتستحيل المفارقة بين التقارير الثلاثة.
     */
    public function expenses(Request $request): JsonResponse
    {
        $data = $this->validatePeriod($request);

        return response()->json($this->groupedByPeriod(
            granularity: $request->input('granularity', 'day'),
            categories: CashTransaction::EXPENSE_CATEGORIES,
            from: $data['date_from'] ?? null,
            to: $data['date_to'] ?? null,
            yearId: $data['academic_year_id'] ?? null,
        ));
    }

    /**
     * مداخيل التلاميذ: المبالغ من الدفتر، والهوية من الدفعة والتسجيل.
     */
    public function revenueByStudent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = $this->paymentDimensionQuery($data)
            ->select([
                's.id as student_id',
                's.student_code',
                's.first_name',
                's.last_name',
                'sec.name as section',
                'l.name as level',
            ])
            ->selectRaw('SUM(ct.amount) as total')
            ->selectRaw('COUNT(DISTINCT p.id) as payments_count')
            ->groupBy('s.id', 's.student_code', 's.first_name', 's.last_name', 'sec.name', 'l.name')
            ->orderByDesc('total');

        if (! empty($data['section_id'])) {
            $query->where('e.section_id', $data['section_id']);
        }

        if (! empty($data['search'])) {
            $term = '%'.$data['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('s.first_name', 'like', $term)
                    ->orWhere('s.last_name', 'like', $term)
                    ->orWhere('s.student_code', 'like', $term);
            });
        }

        $rows = $query->get()->map(fn ($row) => [
            'student_id' => (int) $row->student_id,
            'student_code' => $row->student_code,
            'name' => trim($row->first_name.' '.$row->last_name),
            'level' => $row->level,
            'section' => $row->section,
            'payments_count' => (int) $row->payments_count,
            'total' => round((float) $row->total, 2),
        ])->values();

        return response()->json([
            'filters' => $data,
            'rows' => $rows,
            'summary' => [
                'students_count' => $rows->count(),
                'total' => round($rows->sum('total'), 2),
            ],
        ]);
    }

    /**
     * مداخيل الأقسام: تجميع نفس أسطر الدفتر على مستوى القسم،
     * فيتطابق مجموعها مع مداخيل التلاميذ لنفس الفترة حتماً.
     */
    public function revenueByClassroom(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
        ]);

        $rows = $this->paymentDimensionQuery($data)
            ->select([
                'sec.id as section_id',
                'sec.name as section',
                'l.id as level_id',
                'l.name as level',
            ])
            ->selectRaw('SUM(ct.amount) as total')
            ->selectRaw('COUNT(DISTINCT s.id) as students_count')
            ->selectRaw('COUNT(DISTINCT p.id) as payments_count')
            ->groupBy('sec.id', 'sec.name', 'l.id', 'l.name')
            ->orderBy('l.id')
            ->orderBy('sec.name')
            ->get()
            ->map(fn ($row) => [
                'section_id' => $row->section_id !== null ? (int) $row->section_id : null,
                'section' => $row->section,
                'level_id' => $row->level_id !== null ? (int) $row->level_id : null,
                'level' => $row->level,
                'students_count' => (int) $row->students_count,
                'payments_count' => (int) $row->payments_count,
                'total' => round((float) $row->total, 2),
            ])
            ->values();

        return response()->json([
            'filters' => $data,
            'rows' => $rows,
            'summary' => [
                'sections_count' => $rows->count(),
                'total' => round($rows->sum('total'), 2),
            ],
        ]);
    }

    /**
     * مداخيل السنوات: مداخيل ومصاريف ودخل صافي لكل سنة دراسية.
     */
    public function revenueByYear(): JsonResponse
    {
        $rows = DB::table('cash_transactions as ct')
            ->leftJoin('academic_years as ay', 'ay.id', '=', 'ct.academic_year_id')
            ->whereNull('ct.cancelled_at')
            ->select(['ct.academic_year_id', 'ay.name as academic_year'])
            ->selectRaw($this->sumIf(CashTransaction::INCOME_CATEGORIES).' as income')
            ->selectRaw($this->sumIf(CashTransaction::EXPENSE_CATEGORIES).' as expenses')
            ->selectRaw($this->sumIf(CashTransaction::PRIOR_YEAR_DEBT_CATEGORIES).' as prior_year_debt')
            ->selectRaw($this->sumIf([CashTransaction::CATEGORY_WITHDRAWAL]).' as withdrawals')
            ->groupBy('ct.academic_year_id', 'ay.name')
            ->orderByRaw('ay.name is null, ay.name desc')
            ->get()
            ->map(function ($row) {
                $income = round((float) $row->income, 2);
                $expenses = round((float) $row->expenses, 2);
                $prior = round((float) $row->prior_year_debt, 2);
                $net = round($income - $expenses, 2);

                return [
                    'academic_year_id' => $row->academic_year_id !== null ? (int) $row->academic_year_id : null,
                    'academic_year' => $row->academic_year ?? 'دون سنة محدّدة',
                    'income' => $income,
                    'expenses' => $expenses,
                    'net_income' => $net,
                    // قبض متخلّدات سنوات سابقة يظهر مستقلاً: نقد لا مدخول.
                    'prior_year_debt' => $prior,
                    'withdrawals' => round((float) $row->withdrawals, 2),
                    'balance' => round($net + $prior - (float) $row->withdrawals, 2),
                ];
            })
            ->values();

        return response()->json([
            'rows' => $rows,
            'summary' => [
                'income' => round($rows->sum('income'), 2),
                'expenses' => round($rows->sum('expenses'), 2),
                'net_income' => round($rows->sum('net_income'), 2),
                'prior_year_debt' => round($rows->sum('prior_year_debt'), 2),
                'balance' => round($rows->sum('balance'), 2),
            ],
        ]);
    }

    /**
     * صفحة قسم واحد: بنود مداخيله، وتلاميذه الدافعون مرتّبين تنازلياً،
     * وعدد المسجّلين فيه مقابل عدد من دفع.
     *
     * فارق العددين هو معلومة الإدارة الحقيقية: مجموع ما دخل لا يقول شيئاً
     * عن عدد من لم يدفع بعد، وهو ما تُتابع من أجله الأقسام أصلاً.
     */
    public function classroomDetail(Request $request, Section $section): JsonResponse
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
        ]);

        $students = $this->paymentDimensionQuery($data)
            ->where('e.section_id', $section->id)
            ->select([
                's.id as student_id',
                's.student_code',
                's.first_name',
                's.last_name',
            ])
            ->selectRaw('SUM(ct.amount) as total')
            ->selectRaw('COUNT(DISTINCT p.id) as payments_count')
            ->groupBy('s.id', 's.student_code', 's.first_name', 's.last_name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'student_id' => (int) $row->student_id,
                'student_code' => $row->student_code,
                'name' => trim($row->first_name.' '.$row->last_name),
                'payments_count' => (int) $row->payments_count,
                'total' => round((float) $row->total, 2),
            ])
            ->values();

        $totals = $this->categoryTotals(
            $this->paymentDimensionQuery($data)->where('e.section_id', $section->id)
        );

        // عدد المسجّلين يُقرأ من التسجيلات لا من الدفعات، فيظهر من لم يدفع أيضاً.
        $enrolled = Enrollment::where('section_id', $section->id)
            ->when(
                ! empty($data['academic_year_id']),
                fn ($q) => $q->where('academic_year_id', $data['academic_year_id'])
            )
            ->count();

        $total = round($students->sum('total'), 2);

        return response()->json([
            'filters' => $data,
            'section' => [
                'id' => $section->id,
                'name' => $section->name,
                'level' => $this->levelNameForSection($section->id),
            ],
            'by_category' => $this->linesFor(CashTransaction::INCOME_CATEGORIES, $totals),
            'students' => $students,
            'summary' => [
                'enrolled_count' => $enrolled,
                'payers_count' => $students->count(),
                'unpaid_count' => max($enrolled - $students->count(), 0),
                'payments_count' => (int) $students->sum('payments_count'),
                'total' => $total,
            ],
        ]);
    }

    /**
     * صفحة تلميذ واحد: وصولاته مرتّبة من الأحدث، وكل وصل مفصّل إلى بنوده.
     *
     * الوصولات الملغاة تُعرض مع سببها ولا تدخل في المجموع: حجبها يجعل الولي
     * يسأل عن وصل يحمله ولا أثر له في الشاشة، وعدّها يفسد المحاسبة.
     */
    public function studentDetail(Request $request, Student $student): JsonResponse
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
        ]);

        $rows = DB::table('cash_transactions as ct')
            ->join('payments as p', 'p.id', '=', 'ct.source_id')
            ->where('ct.source_type', (new Payment)->getMorphClass())
            ->where('p.student_id', $student->id)
            // قبض التلميذ كله: مداخيل السنة الحالية + قبض ديون السنوات السابقة.
            ->whereIn('ct.category', CashTransaction::CASH_INFLOW_CATEGORIES)
            ->when(
                ! empty($data['date_from']),
                fn ($q) => $q->whereDate('ct.transaction_date', '>=', $data['date_from'])
            )
            ->when(
                ! empty($data['date_to']),
                fn ($q) => $q->whereDate('ct.transaction_date', '<=', $data['date_to'])
            )
            ->when(
                ! empty($data['academic_year_id']),
                fn ($q) => $q->where('ct.academic_year_id', $data['academic_year_id'])
            )
            ->select([
                'ct.id as line_id',
                'ct.category',
                'ct.amount',
                'ct.transaction_date',
                'ct.cancelled_at',
                'ct.cancellation_reason',
                'p.id as payment_id',
                'p.method',
                'p.reference',
            ])
            ->orderByDesc('ct.transaction_date')
            ->orderByDesc('p.id')
            ->get();

        $payments = [];
        $totals = [];
        $total = 0.0;

        foreach ($rows as $row) {
            $amount = round((float) $row->amount, 2);
            $cancelled = $row->cancelled_at !== null;
            $key = (int) $row->payment_id;

            if (! isset($payments[$key])) {
                $payments[$key] = [
                    'payment_id' => $key,
                    'transaction_date' => $row->transaction_date,
                    'method' => $row->method,
                    'reference' => $row->reference,
                    'cancelled' => $cancelled,
                    'cancellation_reason' => $row->cancellation_reason,
                    'lines' => [],
                    'total' => 0.0,
                ];
            }

            $payments[$key]['lines'][] = [
                'category' => $row->category,
                'label' => CashTransaction::CATEGORY_LABELS[$row->category] ?? $row->category,
                'amount' => $amount,
            ];
            $payments[$key]['total'] = round($payments[$key]['total'] + $amount, 2);

            if (! $cancelled) {
                $totals[$row->category] = round(($totals[$row->category] ?? 0.0) + $amount, 2);
                $total = round($total + $amount, 2);
            }
        }

        $enrollment = DB::table('enrollments as e')
            ->leftJoin('sections as sec', 'sec.id', '=', 'e.section_id')
            ->leftJoin('levels as l', 'l.id', '=', 'e.level_id')
            ->leftJoin('academic_years as ay', 'ay.id', '=', 'e.academic_year_id')
            ->where('e.student_id', $student->id)
            ->whereNull('e.deleted_at')
            ->select(['sec.name as section', 'l.name as level', 'ay.name as academic_year'])
            ->orderByDesc('e.id')
            ->first();

        $payments = array_values($payments);

        return response()->json([
            'filters' => $data,
            'student' => [
                'id' => $student->id,
                'student_code' => $student->student_code,
                'name' => trim($student->first_name.' '.$student->last_name),
                'level' => $enrollment->level ?? null,
                'section' => $enrollment->section ?? null,
                'academic_year' => $enrollment->academic_year ?? null,
                'guardian_phone' => $student->guardian_phone,
            ],
            'by_category' => $this->linesFor(CashTransaction::INCOME_CATEGORIES, $totals),
            'payments' => $payments,
            'summary' => [
                'payments_count' => count(array_filter($payments, fn ($p) => ! $p['cancelled'])),
                'cancelled_count' => count(array_filter($payments, fn ($p) => $p['cancelled'])),
                'total' => $total,
            ],
        ]);
    }

    // ==================== الدوال المساعدة ====================

    private function validatePeriod(Request $request): array
    {
        return $request->validate([
            'granularity' => ['nullable', 'string', 'in:day,month,year'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
        ]);
    }

    /**
     * أرقام الدخل الصافي لفترة واحدة انطلاقاً من مجاميع بنودها.
     *
     * السحب لا يدخل في الدخل الصافي لأنه نقل أموال لا استهلاك، لكنه يُنقِص الرصيد.
     *
     * @param  array<string,float>  $totals
     * @return array<string,mixed>
     */
    private function netFigures(string $period, array $totals): array
    {
        $income = $this->linesFor(CashTransaction::INCOME_CATEGORIES, $totals);
        $expenses = $this->linesFor(CashTransaction::EXPENSE_CATEGORIES, $totals);
        $withdrawals = round($totals[CashTransaction::CATEGORY_WITHDRAWAL] ?? 0.0, 2);
        // تحصيل ديون السنوات السابقة: نقد في الصندوق لا مدخول للفترة.
        $priorYearDebt = round($totals[CashTransaction::CATEGORY_PRIOR_YEAR_DEBT] ?? 0.0, 2);

        $incomeTotal = round(array_sum(array_column($income, 'total')), 2);
        $expenseTotal = round(array_sum(array_column($expenses, 'total')), 2);
        $net = round($incomeTotal - $expenseTotal, 2);

        return [
            'period' => $period,
            'income' => ['lines' => $income, 'total' => $incomeTotal],
            'expenses' => ['lines' => $expenses, 'total' => $expenseTotal],
            'net_income' => $net,
            'prior_year_debt' => $priorYearDebt,
            'withdrawals' => $withdrawals,
            'balance' => round($net + $priorYearDebt - $withdrawals, 2),
        ];
    }

    /**
     * مجاميع البنود لاستعلام أبعاد جاهز.
     *
     * @return array<string,float>
     */
    private function categoryTotals($query): array
    {
        $totals = [];

        $rows = $query
            ->selectRaw('ct.category as category, SUM(ct.amount) as total')
            ->groupBy('ct.category')
            ->get();

        foreach ($rows as $row) {
            $totals[$row->category] = round((float) $row->total, 2);
        }

        return $totals;
    }

    /**
     * اسم مستوى القسم يُستنتج من تسجيلاته لا من عمود في sections،
     * فيبقى سليماً مهما كانت علاقة القسم بالمستوى في المخطّط.
     */
    private function levelNameForSection(int $sectionId): ?string
    {
        return DB::table('enrollments as e')
            ->join('levels as l', 'l.id', '=', 'e.level_id')
            ->where('e.section_id', $sectionId)
            ->whereNull('e.deleted_at')
            ->orderByDesc('e.id')
            ->value('l.name');
    }

    private function base(?string $from, ?string $to, ?int $yearId = null)
    {
        $query = DB::table('cash_transactions')->whereNull('cancelled_at');

        if ($from !== null) {
            $query->whereDate('transaction_date', '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate('transaction_date', '<=', $to);
        }

        if ($yearId !== null) {
            $query->where('academic_year_id', $yearId);
        }

        return $query;
    }

    /**
     * ملخّص فترة: بنود المداخيل والمصاريف والسحوبات والرصيد.
     */
    private function summarize(?string $from, ?string $to, ?int $yearId = null): array
    {
        $rows = $this->base($from, $to, $yearId)
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $totals[$row->category] = round((float) $row->total, 2);
        }

        $figures = $this->netFigures((string) ($to ?? ''), $totals);

        return [
            'date_from' => $from,
            'date_to' => $to,
            'income' => $figures['income'],
            'expenses' => $figures['expenses'],
            'net_income' => $figures['net_income'],
            'prior_year_debt' => $figures['prior_year_debt'],
            'withdrawals' => $figures['withdrawals'],
            'balance' => $figures['balance'],
        ];
    }

    /**
     * يُرجع كل البنود بترتيب ثابت ولو كانت أصفاراً، لأن تقريراً يتغير عدد أسطره
     * من يوم لآخر يصعب تدقيقه ومقارنته.
     *
     * @param  array<int,string>  $categories
     * @param  array<string,float>  $totals
     * @return array<int,array<string,mixed>>
     */
    private function linesFor(array $categories, array $totals): array
    {
        $lines = [];

        foreach ($categories as $category) {
            $lines[] = [
                'category' => $category,
                'label' => CashTransaction::CATEGORY_LABELS[$category] ?? $category,
                'total' => $totals[$category] ?? 0.0,
            ];
        }

        return $lines;
    }

    /**
     * أسطر الحركات التفصيلية لفترة.
     */
    private function lines(?string $from, ?string $to, ?int $yearId = null): array
    {
        return $this->base($from, $to, $yearId)
            ->select(['id', 'transaction_date', 'direction', 'category', 'amount', 'description'])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'transaction_date' => $row->transaction_date,
                'direction' => $row->direction,
                'category' => $row->category,
                'label' => CashTransaction::CATEGORY_LABELS[$row->category] ?? $row->category,
                'amount' => round((float) $row->amount, 2),
                'description' => $row->description,
            ])
            ->all();
    }

    /**
     * تجميع زمني موحّد: سطر لكل فترة مفصّل على البنود، مع مجموع عام ومجموع لكل بند.
     *
     * @param  array<int,string>  $categories
     */
    private function groupedByPeriod(
        string $granularity,
        array $categories,
        ?string $from,
        ?string $to,
        ?int $yearId
    ): array {
        $expression = $this->periodExpression($granularity);

        $rows = $this->base($from, $to, $yearId)
            ->whereIn('category', $categories)
            ->selectRaw($expression.' as period')
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy(DB::raw($expression), 'category')
            ->orderBy('period')
            ->get();

        $periods = [];
        $byCategory = array_fill_keys($categories, 0.0);

        foreach ($rows as $row) {
            $total = round((float) $row->total, 2);

            if (! isset($periods[$row->period])) {
                $periods[$row->period] = array_fill_keys($categories, 0.0);
            }

            $periods[$row->period][$row->category] = $total;
            $byCategory[$row->category] += $total;
        }

        $result = [];
        foreach ($periods as $period => $totals) {
            $result[] = [
                'period' => (string) $period,
                'by_category' => $this->linesFor($categories, $totals),
                'total' => round(array_sum($totals), 2),
            ];
        }

        return [
            'granularity' => $granularity,
            'date_from' => $from,
            'date_to' => $to,
            'rows' => $result,
            'summary' => [
                'periods_count' => count($result),
                'by_category' => $this->linesFor($categories, $byCategory),
                'total' => round(array_sum($byCategory), 2),
            ],
        ];
    }

    /**
     * الأساس المشترك لتقارير الأبعاد (تلميذ / قسم):
     * المبالغ من الدفتر، والربط مع الدفعة يتم بـ source_type/source_id.
     */
    private function paymentDimensionQuery(array $data)
    {
        $query = DB::table('cash_transactions as ct')
            ->join('payments as p', 'p.id', '=', 'ct.source_id')
            ->join('students as s', 's.id', '=', 'p.student_id')
            ->leftJoin('enrollments as e', 'e.id', '=', 'p.enrollment_id')
            ->leftJoin('sections as sec', 'sec.id', '=', 'e.section_id')
            ->leftJoin('levels as l', 'l.id', '=', 'e.level_id')
            ->where('ct.source_type', (new Payment)->getMorphClass())
            ->whereNull('ct.cancelled_at')
            ->whereIn('ct.category', CashTransaction::INCOME_CATEGORIES);

        if (! empty($data['date_from'])) {
            $query->whereDate('ct.transaction_date', '>=', $data['date_from']);
        }

        if (! empty($data['date_to'])) {
            $query->whereDate('ct.transaction_date', '<=', $data['date_to']);
        }

        if (! empty($data['academic_year_id'])) {
            $query->where('ct.academic_year_id', $data['academic_year_id']);
        }

        return $query;
    }

    /**
     * تعبير SQL للتجميع الزمني متوافق مع محرّك القاعدة.
     *
     * SQLite هو محرّك التشغيل الحالي، لكن المنصة مرشّحة للانتقال إلى MySQL،
     * وترك strftime مباشرة في الكود كان سيُسقِط كل التقارير عند الهجرة.
     */
    private function periodExpression(string $granularity): string
    {
        $driver = DB::connection()->getDriverName();

        $formats = [
            'day' => ['sqlite' => '%Y-%m-%d', 'mysql' => '%Y-%m-%d', 'pgsql' => 'YYYY-MM-DD'],
            'month' => ['sqlite' => '%Y-%m',    'mysql' => '%Y-%m',    'pgsql' => 'YYYY-MM'],
            'year' => ['sqlite' => '%Y',       'mysql' => '%Y',       'pgsql' => 'YYYY'],
        ];

        $granularity = array_key_exists($granularity, $formats) ? $granularity : 'day';

        return match ($driver) {
            'sqlite' => "strftime('".$formats[$granularity]['sqlite']."', transaction_date)",
            'pgsql' => "to_char(transaction_date, '".$formats[$granularity]['pgsql']."')",
            default => "DATE_FORMAT(transaction_date, '".$formats[$granularity]['mysql']."')",
        };
    }

    /**
     * مجموع شرطي لمجموعة بنود داخل نفس الاستعلام، دون تمرير أي مدخل مستخدم:
     * القيم تأتي من ثوابت الموديل حصراً فلا يوجد احتمال حقن SQL.
     *
     * @param  array<int,string>  $categories
     */
    private function sumIf(array $categories): string
    {
        $list = implode(', ', array_map(fn ($c) => "'".$c."'", $categories));

        return 'COALESCE(SUM(CASE WHEN ct.category IN ('.$list.') THEN ct.amount ELSE 0 END), 0)';
    }
}
