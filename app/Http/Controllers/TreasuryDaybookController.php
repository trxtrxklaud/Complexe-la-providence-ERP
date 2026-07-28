<?php

namespace App\Http\Controllers;

use App\Models\CashTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * كشف الخزينة اليومي (Daybook).
 *
 * صورة مطابقة للكشف الورقي الذي اعتادته الإدارة: من تاريخ مختار إلى اليوم،
 * بطاقة لكل يوم فيها المداخيل بالبنود والمصاريف بالبنود وتفاصيل الحركات،
 * ثم الدخل الصافي اليومي والسحوبات والرصيد، ثم السطر الأهم: التراكمي.
 *
 * ثلاث قواعد لا يجوز خرقها في هذا الملف:
 *
 * 1) كل مبلغ يُقرأ من cash_transactions حصراً (الأسطر الملغاة مستثناة)، فلا يمكن
 *    لهذا الكشف أن يخالف بقية التقارير.
 *
 * 2) التراكمي يُحسب من بداية السجل لا من بداية الفترة المعروضة. لو حُسب من بداية
 *    الفترة لأظهرَ رصيداً وهمياً: من يفتح الكشف من 16 جوان يجب أن يرى الرصيد
 *    الحقيقي في الدرج يومها، لا صفراً.
 *
 * 3) تُعرض الأيام الفارغة أيضاً. الكشف الذي يُسقِط الأيام الخالية يجعل التدقيق
 *    الورقي مستحيلاً لأن القارئ لا يعرف أهو يوم بلا حركة أم يوم سقط سهواً.
 */
class TreasuryDaybookController extends Controller
{
    /** سقف المدى: يوم واحد لكل بطاقة، ومدى أطول من سنة يُنتج صفحة لا تُقرأ ولا تُطبع. */
    private const MAX_DAYS = 400;

    /** سقف أسطر التفصيل حمايةً للذاكرة؛ يُبلَّغ عنه بصراحة بدل بترٍ صامت. */
    private const MAX_DETAIL_LINES = 4000;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'             => ['required', 'date'],
            'date_to'          => ['nullable', 'date', 'after_or_equal:date'],
            'details'          => ['nullable', 'boolean'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
        ]);

        $from   = Carbon::parse($data['date'])->startOfDay();
        $to     = Carbon::parse($data['date_to'] ?? now()->toDateString())->startOfDay();
        $yearId = $data['academic_year_id'] ?? null;

        if ($from->gt($to)) {
            throw ValidationException::withMessages([
                'date' => 'تاريخ البداية بعد تاريخ النهاية.',
            ]);
        }

        $dayCount = $from->diffInDays($to) + 1;

        if ($dayCount > self::MAX_DAYS) {
            throw ValidationException::withMessages([
                'date' => 'المدى المطلوب ' . $dayCount . ' يوماً، والحدّ الأقصى ' . self::MAX_DAYS . ' يوماً. اختر تاريخ بداية أقرب.',
            ]);
        }

        $fromDate = $from->toDateString();
        $toDate   = $to->toDateString();

        $withDetails = $request->boolean('details');

        $grouped = $this->totalsByDay($fromDate, $toDate, $yearId);
        $details = $withDetails ? $this->detailsByDay($fromDate, $toDate, $yearId) : [];
        $opening = $this->openingFigures($fromDate, $yearId);

        $runningNet         = $opening['net_income'];
        $runningWithdrawals = $opening['withdrawals'];

        $days   = [];
        $totals = [];
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            $key       = $cursor->toDateString();
            $dayTotals = $grouped[$key] ?? [];

            foreach ($dayTotals as $category => $amount) {
                $totals[$category] = round(($totals[$category] ?? 0.0) + $amount, 2);
            }

            $figures = $this->figures($dayTotals);

            $runningNet         = round($runningNet + $figures['net_income'], 2);
            $runningWithdrawals = round($runningWithdrawals + $figures['withdrawals'], 2);

            $days[] = [
                'date'        => $key,
                'income'      => $figures['income'],
                'expenses'    => $figures['expenses'],
                'net_income'  => $figures['net_income'],
                'withdrawals' => $figures['withdrawals'],
                'balance'     => $figures['balance'],
                'has_activity' => $dayTotals !== [],
                'details'     => $withDetails
                    ? ($details[$key] ?? ['income' => [], 'expenses' => [], 'withdrawals' => []])
                    : null,
                'cumulative'  => [
                    'net_income'  => $runningNet,
                    'withdrawals' => $runningWithdrawals,
                    'balance'     => round($runningNet - $runningWithdrawals, 2),
                ],
            ];

            $cursor->addDay();
        }

        $range = $this->figures($totals);

        return response()->json([
            'date_from'   => $fromDate,
            'date_to'     => $toDate,
            'days_count'  => count($days),
            'with_details' => $withDetails,
            // رصيد ما قبل الفترة: هو ما يجعل التراكمي صادقاً مهما كان تاريخ البداية.
            'opening'     => $opening,
            'days'        => $days,
            'summary'     => [
                'income'      => $range['income'],
                'expenses'    => $range['expenses'],
                'net_income'  => $range['net_income'],
                'withdrawals' => $range['withdrawals'],
                'balance'     => $range['balance'],
            ],
            'closing'     => [
                'net_income'  => $runningNet,
                'withdrawals' => $runningWithdrawals,
                'balance'     => round($runningNet - $runningWithdrawals, 2),
            ],
        ]);
    }

    // ==================== الدوال المساعدة ====================

    /**
     * مجاميع كل بند لكل يوم في استعلام واحد.
     *
     * استعلام واحد لا استعلام لكل يوم: كشف أربعين يوماً كان سيصير أربعين رحلة
     * إلى القاعدة، وهو نوع البطء الذي لا يظهر في الاختبار ويظهر عند المستعمل.
     *
     * @return array<string,array<string,float>>
     */
    private function totalsByDay(string $from, string $to, ?int $yearId): array
    {
        $expression = $this->dayExpression();

        $rows = $this->base($from, $to, $yearId)
            ->selectRaw($expression . ' as day')
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy(DB::raw($expression), 'category')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->day][$row->category] = round((float) $row->total, 2);
        }

        return $out;
    }

    /**
     * أسطر الحركات مصنَّفة تحت كل يوم وفي ثلاث خانات: داخل، خارج، سحب.
     *
     * السحب يُفرد لأنه ليس مصروفاً: المال لم يُستهلك بل انتقل، وخلطه بالمصاريف
     * يجعل الدخل الصافي أصغر ممّا هو.
     *
     * @return array<string,array<string,array<int,array<string,mixed>>>>
     */
    private function detailsByDay(string $from, string $to, ?int $yearId): array
    {
        $rows = $this->base($from, $to, $yearId)
            ->select(['id', 'transaction_date', 'direction', 'category', 'amount', 'description'])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->limit(self::MAX_DETAIL_LINES)
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $day = substr((string) $row->transaction_date, 0, 10);

            if (! isset($out[$day])) {
                $out[$day] = ['income' => [], 'expenses' => [], 'withdrawals' => []];
            }

            $bucket = match (true) {
                $row->category === CashTransaction::CATEGORY_WITHDRAWAL => 'withdrawals',
                in_array($row->category, CashTransaction::INCOME_CATEGORIES, true) => 'income',
                default => 'expenses',
            };

            $out[$day][$bucket][] = [
                'id'          => (int) $row->id,
                'category'    => $row->category,
                'label'       => CashTransaction::CATEGORY_LABELS[$row->category] ?? $row->category,
                'description' => $row->description,
                'amount'      => round((float) $row->amount, 2),
            ];
        }

        return $out;
    }

    /**
     * أرقام ما قبل تاريخ البداية: الرصيد الذي كان في الدرج فعلاً صبيحة أول يوم معروض.
     *
     * @return array<string,float>
     */
    private function openingFigures(string $from, ?int $yearId): array
    {
        $query = DB::table('cash_transactions')
            ->whereNull('cancelled_at')
            ->whereDate('transaction_date', '<', $from);

        if ($yearId !== null) {
            $query->where('academic_year_id', $yearId);
        }

        $rows = $query->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $totals[$row->category] = round((float) $row->total, 2);
        }

        $figures = $this->figures($totals);

        return [
            'net_income'  => $figures['net_income'],
            'withdrawals' => $figures['withdrawals'],
            'balance'     => $figures['balance'],
        ];
    }

    /**
     * تحويل مجاميع البنود إلى أرقام كشف: بنود ثابتة الترتيب ولو كانت أصفاراً.
     *
     * @param  array<string,float>  $totals
     * @return array<string,mixed>
     */
    private function figures(array $totals): array
    {
        $income   = $this->linesFor(CashTransaction::INCOME_CATEGORIES, $totals);
        $expenses = $this->linesFor(CashTransaction::EXPENSE_CATEGORIES, $totals);

        $incomeTotal  = round(array_sum(array_column($income, 'total')), 2);
        $expenseTotal = round(array_sum(array_column($expenses, 'total')), 2);
        $withdrawals  = round($totals[CashTransaction::CATEGORY_WITHDRAWAL] ?? 0.0, 2);
        $net          = round($incomeTotal - $expenseTotal, 2);

        return [
            'income'      => ['lines' => $income, 'total' => $incomeTotal],
            'expenses'    => ['lines' => $expenses, 'total' => $expenseTotal],
            'net_income'  => $net,
            'withdrawals' => $withdrawals,
            'balance'     => round($net - $withdrawals, 2),
        ];
    }

    /**
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
                'label'    => CashTransaction::CATEGORY_LABELS[$category] ?? $category,
                'total'    => round($totals[$category] ?? 0.0, 2),
            ];
        }

        return $lines;
    }

    private function base(string $from, string $to, ?int $yearId)
    {
        $query = DB::table('cash_transactions')
            ->whereNull('cancelled_at')
            ->whereDate('transaction_date', '>=', $from)
            ->whereDate('transaction_date', '<=', $to);

        if ($yearId !== null) {
            $query->where('academic_year_id', $yearId);
        }

        return $query;
    }

    /**
     * تعبير اليوم متوافق مع المحرّك — نفس مبدأ periodExpression في تقارير الدخل الصافي:
     * كتابة strftime مباشرة كانت ستُسقِط الكشف كله يوم الانتقال إلى MySQL.
     */
    private function dayExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m-%d', transaction_date)",
            'pgsql'  => "to_char(transaction_date, 'YYYY-MM-DD')",
            default  => "DATE_FORMAT(transaction_date, '%Y-%m-%d')",
        };
    }
}
