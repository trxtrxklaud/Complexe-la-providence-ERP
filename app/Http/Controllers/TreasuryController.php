<?php

namespace App\Http\Controllers;

use App\Models\CashTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * الخزينة: تُقرأ حصراً من الدفتر النقدي المركزي cash_transactions،
 * لا من جداول المدفوعات أو المصاريف مباشرة، ضماناً لتطابق الأرقام مع بقية التقارير.
 */
class TreasuryController extends Controller
{
    /**
     * سجلّ حركات الخزينة مع مرشّحات التاريخ والاتجاه والبند.
     */
    public function history(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'direction' => ['nullable', 'in:in,out'],
            'category' => ['nullable', 'string', 'max:40'],
        ]);

        $from = $request->input('date_from');
        $to = $request->input('date_to');

        $query = CashTransaction::with([
            'academicYear:id,name',
            'createdBy:id,first_name,last_name',
            'cancelledBy:id,first_name,last_name',
        ])
            ->betweenDates($from, $to)
            ->when(! $request->boolean('include_cancelled'), fn ($q) => $q->whereNull('cancelled_at'))
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->input('direction')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->input('category')))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id');

        $page = $query->paginate(min($request->integer('per_page', 30), 100));

        // التسمية العربية للبند تُرفق مع كل سطر حتى لا تُكرَّر في الواجهة.
        $page->getCollection()->each(fn (CashTransaction $t) => $t->append('label'));

        return response()->json([
            'transactions' => $page,
            'summary' => $this->summary($from, $to),
        ]);
    }

    /**
     * رصيد الخزينة: المداخيل ناقص المصاريف ناقص السحوبات.
     */
    public function balance(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        return response()->json(
            $this->summary($request->input('date_from'), $request->input('date_to'))
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function summary(?string $from, ?string $to): array
    {
        $base = fn () => CashTransaction::query()->active()->betweenDates($from, $to);

        $income = (float) $base()->income()->sum('amount');
        $expenses = (float) $base()->expense()->sum('amount');
        $withdrawals = (float) $base()->withdrawals()->sum('amount');
        // قبض ديون السنوات السابقة: نقد داخل لا مدخول — يدخل في الرصيد وحده.
        $priorYearDebt = (float) $base()->priorYearDebt()->sum('amount');

        // تفصيل كل بند على حدة، بنفس ترتيب التقرير القديم.
        $byCategory = $base()
            ->selectRaw('category, direction, SUM(amount) as total')
            ->groupBy('category', 'direction')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category,
                'label' => CashTransaction::CATEGORY_LABELS[$row->category] ?? $row->category,
                'direction' => $row->direction,
                'total' => round((float) $row->total, 2),
            ])
            ->values();

        $netIncome = round($income - $expenses, 2);

        return [
            'date_from' => $from,
            'date_to' => $to,
            'income' => round($income, 2),
            'expenses' => round($expenses, 2),
            // الدخل الصافي قبل السحوبات (لا يشمل قبض ديون السنوات السابقة).
            'net_income' => $netIncome,
            'prior_year_debt' => round($priorYearDebt, 2),
            'withdrawals' => round($withdrawals, 2),
            // الرصيد النهائي: يضمّ قبض ديون السنوات السابقة لأنه نقد دخل الصندوق.
            'balance' => round($netIncome + $priorYearDebt - $withdrawals, 2),
            'by_category' => $byCategory,
        ];
    }
}
