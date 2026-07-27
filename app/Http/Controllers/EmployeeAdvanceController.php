<?php

namespace App\Http\Controllers;

use App\Models\EmployeeAdvance;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeAdvanceController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function index(Request $request): JsonResponse
    {
        $items = EmployeeAdvance::with([
            'employee:id,first_name,last_name,job_title',
            'academicYear:id,name',
            'createdBy:id,first_name,last_name',
            'cancelledBy:id,first_name,last_name',
        ])
            ->when($request->filled('employee_id'),      fn ($q) => $q->where('employee_id',      $request->integer('employee_id')))
            ->when($request->filled('academic_year_id'), fn ($q) => $q->where('academic_year_id', $request->integer('academic_year_id')))
            ->when($request->filled('status'),           fn ($q) => $q->where('status',           $request->input('status')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('advance_date', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'),   fn ($q) => $q->whereDate('advance_date', '<=', $request->input('date_to')))
            ->when($request->boolean('exclude_cancelled'), fn ($q) => $q->whereNull('cancelled_at'))
            ->latest('advance_date')
            ->latest('id')
            ->paginate(min($request->integer('per_page', 20), 100));

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id'      => ['required', 'integer', 'exists:employees,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'amount'           => ['required', 'numeric', 'min:0.01'],
            'advance_date'     => ['required', 'date'],
            'method'           => ['nullable', 'string', 'max:50'],
            'reason'           => ['nullable', 'string', 'max:200'],
            'notes'            => ['nullable', 'string'],
        ]);
        $data['created_by'] = $request->user()?->id;
        $data['status']     = 'pending';

        $advance = DB::transaction(function () use ($data) {
            $advance = EmployeeAdvance::create($data);
            $this->ledger->recordEmployeeAdvance($advance);

            return $advance;
        });

        return response()->json($advance->load(['employee:id,first_name,last_name', 'academicYear:id,name']), 201);
    }

    public function show(EmployeeAdvance $advance): JsonResponse
    {
        return response()->json($advance->load([
            'employee:id,first_name,last_name,job_title',
            'academicYear:id,name',
            'createdBy:id,first_name,last_name',
            'cancelledBy:id,first_name,last_name',
        ]));
    }

    public function update(Request $request, EmployeeAdvance $advance): JsonResponse
    {
        if ($advance->cancelled_at) {
            return response()->json(['message' => 'لا يمكن تعديل سلفة ملغاة'], 422);
        }

        $data = $request->validate([
            'employee_id'      => ['sometimes', 'integer', 'exists:employees,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'amount'           => ['sometimes', 'numeric', 'min:0.01'],
            'advance_date'     => ['sometimes', 'date'],
            'method'           => ['nullable', 'string', 'max:50'],
            'reason'           => ['nullable', 'string', 'max:200'],
            'notes'            => ['nullable', 'string'],
        ]);

        $advance = DB::transaction(function () use ($advance, $data) {
            $advance->update($data);
            $fresh = $advance->fresh();
            $this->ledger->recordEmployeeAdvance($fresh);

            return $fresh;
        });

        return response()->json($advance->load(['employee:id,first_name,last_name', 'academicYear:id,name']));
    }

    /**
     * تسجيل خلاص جزئي أو كلّي للسلفة.
     *
     * الخلاص لا يُسقَط في الدفتر هنا: استرجاع السلفة يُخصم عادةً من الراتب،
     * فإسقاطه كمدخول نقدي مستقل يُضاعف الأثر. يُكتفى بتحديث حالة السلفة.
     */
    public function settle(Request $request, EmployeeAdvance $advance): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        if ($advance->cancelled_at) {
            return response()->json(['message' => 'لا يمكن خلاص سلفة ملغاة'], 422);
        }

        $remaining = round((float) $advance->amount - (float) $advance->settled_amount, 2);

        if ($data['amount'] > $remaining) {
            return response()->json([
                'message' => 'المبلغ (' . $data['amount'] . ') يتجاوز المتبقّي من السلفة (' . $remaining . ')',
            ], 422);
        }

        $settled = round((float) $advance->settled_amount + (float) $data['amount'], 2);

        $advance->update([
            'settled_amount' => $settled,
            'status'         => $settled >= (float) $advance->amount ? 'settled' : 'partial',
        ]);

        return response()->json($advance->fresh()->load(['employee:id,first_name,last_name']));
    }

    public function cancel(Request $request, EmployeeAdvance $advance): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($advance->cancelled_at) {
            return response()->json(['message' => 'هذه السلفة ملغاة مسبقاً'], 422);
        }

        DB::transaction(function () use ($advance, $data, $request) {
            $advance->update([
                'status'              => 'cancelled',
                'cancelled_at'        => now(),
                'cancelled_by'        => $request->user()?->id,
                'cancellation_reason' => $data['reason'],
            ]);

            $this->ledger->cancelFor($advance, $request->user()?->id, $data['reason']);
        });

        return response()->json($advance->fresh()->load([
            'employee:id,first_name,last_name',
            'cancelledBy:id,first_name,last_name',
        ]));
    }
}
