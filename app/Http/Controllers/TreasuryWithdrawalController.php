<?php

namespace App\Http\Controllers;

use App\Models\TreasuryWithdrawal;
use App\Services\AuditService;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * سحوبات الخزينة: حركة نقدية مستقلة لا تُحتسب ضمن المصاريف ولا تؤثر على الدخل الصافي،
 * بل تُخصم من الرصيد بعده — السحب نقل أموال لا استهلاك، فلا يُنقِص الدخل الصافي.
 */
class TreasuryWithdrawalController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function index(Request $request): JsonResponse
    {
        $items = TreasuryWithdrawal::with([
            'academicYear:id,name',
            'createdBy:id,first_name,last_name',
            'cancelledBy:id,first_name,last_name',
        ])
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('withdrawn_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'),   fn ($q) => $q->whereDate('withdrawn_at', '<=', $request->input('date_to')))
            ->when($request->boolean('exclude_cancelled'), fn ($q) => $q->whereNull('cancelled_at'))
            ->latest('withdrawn_at')
            ->latest('id')
            ->paginate(min($request->integer('per_page', 20), 100));

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount'           => ['required', 'numeric', 'min:0.01'],
            'withdrawn_at'     => ['required', 'date'],
            'type'             => ['nullable', 'string', 'max:100'],
            'note'             => ['nullable', 'string'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
        ]);
        $data['created_by'] = $request->user()?->id;

        $withdrawal = DB::transaction(function () use ($data) {
            $withdrawal = TreasuryWithdrawal::create($data);
            $this->ledger->recordWithdrawal($withdrawal);

            return $withdrawal;
        });

        AuditService::log('withdrawal.create', 'سحب من الخزينة بمبلغ '.$withdrawal->amount.' د.ت', $withdrawal, ['amount' => $withdrawal->amount]);

        return response()->json($withdrawal->load('academicYear:id,name'), 201);
    }

    public function show(TreasuryWithdrawal $withdrawal): JsonResponse
    {
        return response()->json($withdrawal->load([
            'academicYear:id,name',
            'createdBy:id,first_name,last_name',
            'cancelledBy:id,first_name,last_name',
        ]));
    }

    public function update(Request $request, TreasuryWithdrawal $withdrawal): JsonResponse
    {
        if ($withdrawal->cancelled_at) {
            return response()->json(['message' => 'لا يمكن تعديل سحب ملغى'], 422);
        }

        $data = $request->validate([
            'amount'           => ['sometimes', 'numeric', 'min:0.01'],
            'withdrawn_at'     => ['sometimes', 'date'],
            'type'             => ['nullable', 'string', 'max:100'],
            'note'             => ['nullable', 'string'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
        ]);

        $withdrawal = DB::transaction(function () use ($withdrawal, $data) {
            $withdrawal->update($data);
            $fresh = $withdrawal->fresh();
            $this->ledger->recordWithdrawal($fresh);

            return $fresh;
        });

        return response()->json($withdrawal->load('academicYear:id,name'));
    }

    public function cancel(Request $request, TreasuryWithdrawal $withdrawal): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($withdrawal->cancelled_at) {
            return response()->json(['message' => 'هذا السحب ملغى مسبقاً'], 422);
        }

        DB::transaction(function () use ($withdrawal, $data, $request) {
            $withdrawal->update([
                'cancelled_at'        => now(),
                'cancelled_by'        => $request->user()?->id,
                'cancellation_reason' => $data['reason'],
            ]);

            $this->ledger->cancelFor($withdrawal, $request->user()?->id, $data['reason']);
        });

        return response()->json($withdrawal->fresh()->load([
            'academicYear:id,name',
            'cancelledBy:id,first_name,last_name',
        ]));
    }
}
