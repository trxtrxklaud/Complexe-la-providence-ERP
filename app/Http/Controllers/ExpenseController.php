<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Expense;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function index(Request $request): JsonResponse
    {
        $items = Expense::with([
            'category:id,name',
            'academicYear:id,name',
            'createdBy:id,first_name,last_name',
            'cancelledBy:id,first_name,last_name',
        ])
            ->when($request->filled('expense_category_id'), fn ($q) => $q->where('expense_category_id', $request->integer('expense_category_id')))
            ->when($request->filled('academic_year_id'),    fn ($q) => $q->where('academic_year_id',    $request->integer('academic_year_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('expense_date', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'),   fn ($q) => $q->whereDate('expense_date', '<=', $request->input('date_to')))
            ->when($request->boolean('exclude_cancelled'), fn ($q) => $q->whereNull('cancelled_at'))
            ->when($request->boolean('cancelled'), fn ($q) => $q->whereNotNull('cancelled_at'))
            ->latest('expense_date')
            ->latest('id')
            ->paginate(min($request->integer('per_page', 20), 100));

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $data['created_by'] = $request->user()?->id;

        // مصروف بلا سنة دراسية يختفي من أي كشف مُرشَّح بالسنة، فتظهر المداخيل
        // وحدها ويبدو الدخل الصافي أكبر ممّا هو. ولأنّ المدرسة تعمل في كل
        // الأشهر (منها أشهر تقع خارج مدى أيّ سنة دراسية)، تُستنتَج السنة من
        // السنة النشطة لا من تاريخ المصروف، مع إبقاء حقّ المستخدم في تحديدها يدويّاً.
        if (($data['academic_year_id'] ?? null) === null) {
            $data['academic_year_id'] = $this->defaultAcademicYearId();
        }

        // المصروف وأثره النقدي يُسجّلان معاً أو لا يُسجّل أيّهما.
        $expense = DB::transaction(function () use ($data) {
            $expense = Expense::create($data);
            $this->ledger->recordExpense($expense);

            return $expense;
        });

        return response()->json($expense->load(['category:id,name', 'academicYear:id,name']), 201);
    }

    public function show(Expense $expense): JsonResponse
    {
        return response()->json($expense->load([
            'category:id,name',
            'academicYear:id,name',
            'createdBy:id,first_name,last_name',
            'cancelledBy:id,first_name,last_name',
        ]));
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        if ($expense->cancelled_at) {
            return response()->json(['message' => 'لا يمكن تعديل مصروف ملغى'], 422);
        }

        $data = $this->validated($request, false);

        $expense = DB::transaction(function () use ($expense, $data) {
            $expense->update($data);
            $fresh = $expense->fresh();
            // إعادة الإسقاط تُبقي المبلغ والتاريخ في الدفتر مطابقَين للمستند.
            $this->ledger->recordExpense($fresh);

            return $fresh;
        });

        return response()->json($expense->load(['category:id,name', 'academicYear:id,name']));
    }

    /**
     * إلغاء موثّق بدل الحذف، مع سحب أثر المصروف من الدفتر النقدي.
     */
    public function cancel(Request $request, Expense $expense): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($expense->cancelled_at) {
            return response()->json(['message' => 'هذا المصروف ملغى مسبقاً'], 422);
        }

        DB::transaction(function () use ($expense, $data, $request) {
            $expense->update([
                'cancelled_at'        => now(),
                'cancelled_by'        => $request->user()?->id,
                'cancellation_reason' => $data['reason'],
            ]);

            $this->ledger->cancelFor($expense, $request->user()?->id, $data['reason']);
        });

        return response()->json($expense->fresh()->load([
            'category:id,name',
            'academicYear:id,name',
            'cancelledBy:id,first_name,last_name',
        ]));
    }

    /**
     * السنة الدراسية النشطة، أو null إن لم توجد.
     *
     * لم أستنتجها من تاريخ المصروف عمداً: أشهر الصيف تقع خارج مدى كلّ السنوات
     * المُعرَّفة، فالاستنتاج من التاريخ كان سيُرجِع null في جويلية وأوت تحديداً،
     * وهما شهران تعملون فيهما فعلاً.
     */
    private function defaultAcademicYearId(): ?int
    {
        $id = AcademicYear::where('is_active', true)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'academic_year_id'    => ['nullable', 'integer', 'exists:academic_years,id'],
            'label'               => [$required, 'string', 'max:200'],
            'amount'              => [$required, 'numeric', 'min:0.01'],
            'expense_date'        => [$required, 'date'],
            'method'              => ['nullable', 'string', 'max:50'],
            'reference'           => ['nullable', 'string', 'max:100'],
            'notes'               => ['nullable', 'string'],
        ]);
    }
}
