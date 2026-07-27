<?php

namespace App\Http\Controllers;

use App\Models\Salary;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalaryController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function index(Request $request): JsonResponse
    {
        $q = Salary::with([
            'employee:id,first_name,last_name',
            'academicYear:id,name',
            'cancelledBy:id,first_name,last_name',
        ])->latest('paid_at')->latest('id');

        if ($request->filled('academic_year_id')) {
            $q->where('academic_year_id', $request->integer('academic_year_id'));
        }
        if ($request->filled('employee_id')) {
            $q->where('employee_id', $request->integer('employee_id'));
        }
        if ($request->boolean('exclude_cancelled')) {
            $q->whereNull('cancelled_at');
        }

        return response()->json($q->paginate(min($request->integer('per_page', 20), 100)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'paid_at' => ['nullable', 'date'],
            'method' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);
        $data['created_by'] = $request->user()?->id;

        // الراتب وأثره النقدي يُسجَّلان معاً أو لا يُسجَّل أيّهما.
        $salary = DB::transaction(function () use ($data) {
            $salary = Salary::create($data);
            $this->ledger->recordSalary($salary);

            return $salary;
        });

        return response()->json(
            $salary->load(['employee:id,first_name,last_name', 'academicYear:id,name']),
            201
        );
    }

    public function show(Salary $salary): JsonResponse
    {
        return response()->json($salary->load(['employee', 'academicYear', 'cancelledBy:id,first_name,last_name']));
    }

    public function update(Request $request, Salary $salary): JsonResponse
    {
        if ($salary->cancelled_at) {
            return response()->json(['message' => 'لا يمكن تعديل راتب ملغى'], 422);
        }

        $data = $request->validate([
            'employee_id' => ['sometimes', 'integer', 'exists:employees,id'],
            'academic_year_id' => ['sometimes', 'integer', 'exists:academic_years,id'],
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'period_from' => ['sometimes', 'date'],
            'period_to' => ['sometimes', 'date'],
            'paid_at' => ['nullable', 'date'],
            'method' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        // إعادة إسقاط الراتب في الدفتر بعد التعديل حتى يبقى المبلغ والتاريخ متطابقَين.
        $salary = DB::transaction(function () use ($salary, $data) {
            $salary->update($data);
            $this->ledger->recordSalary($salary->fresh());

            return $salary->fresh();
        });

        return response()->json($salary->load(['employee:id,first_name,last_name', 'academicYear:id,name']));
    }

    /**
     * إلغاء موثّق للراتب بدل الحذف النهائي (سبب + منفّذ + تاريخ)،
     * مع سحب أثره من الدفتر النقدي المركزي.
     */
    public function cancel(Request $request, Salary $salary): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($salary->cancelled_at) {
            return response()->json(['message' => 'هذا الراتب ملغى مسبقاً'], 422);
        }

        DB::transaction(function () use ($salary, $data, $request) {
            $salary->update([
                'cancelled_at'        => now(),
                'cancelled_by'        => $request->user()?->id,
                'cancellation_reason' => $data['reason'],
            ]);

            $this->ledger->cancelFor($salary, $request->user()?->id, $data['reason']);
        });

        return response()->json(
            $salary->fresh()->load([
                'employee:id,first_name,last_name',
                'academicYear:id,name',
                'cancelledBy:id,first_name,last_name',
            ])
        );
    }
}
