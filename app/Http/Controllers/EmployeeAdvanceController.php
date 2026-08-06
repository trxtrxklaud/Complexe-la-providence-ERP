<?php

namespace App\Http\Controllers;

use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('academic_year_id'), fn ($q) => $q->where('academic_year_id', $request->integer('academic_year_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->boolean('outstanding'), fn ($q) => $q->outstanding())
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('advance_date', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('advance_date', '<=', $request->input('date_to')))
            ->when($request->boolean('exclude_cancelled'), fn ($q) => $q->whereNull('cancelled_at'))
            ->latest('advance_date')
            ->latest('id')
            ->paginate(min($request->integer('per_page', 20), 100));

        return response()->json($items);
    }

    /**
     * منح تسبقة أو سلفة.
     *
     * is_opening يعني دَيناً منقولاً من سنة سابقة: المال خرج من صندوق تلك السنة،
     * فإسقاطه في دفتر السنة الجديدة كان سيُنقِص خزينة لم يخرج منها شيء.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'type' => ['nullable', 'string', 'in:advance,loan'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'advance_date' => ['required', 'date'],
            'method' => ['nullable', 'string', 'max:50'],
            'reason' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string'],
            'is_opening' => ['nullable', 'boolean'],
        ]);

        $data['created_by'] = $request->user()?->id;
        $data['status'] = EmployeeAdvance::STATUS_PENDING;
        $data['type'] = $data['type'] ?? EmployeeAdvance::TYPE_ADVANCE;
        $isOpening = (bool) ($data['is_opening'] ?? false);
        $data['is_opening'] = $isOpening;

        $advance = DB::transaction(function () use ($data, $isOpening) {
            $advance = EmployeeAdvance::create($data);

            if (! $isOpening) {
                $this->ledger->recordEmployeeAdvance($advance);
            }

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
            'repayments' => fn ($q) => $q->orderBy('repaid_at')->orderBy('id'),
        ]));
    }

    public function update(Request $request, EmployeeAdvance $advance): JsonResponse
    {
        if ($advance->cancelled_at) {
            return response()->json(['message' => 'لا يمكن تعديل سلفة ملغاة'], 422);
        }

        if ($advance->settled_by_salary_id !== null) {
            return response()->json([
                'message' => 'هذه التسبقة خُصمت من راتب؛ ألغِ الراتب أوّلاً',
            ], 422);
        }

        // تخفيض مبلغ سلفة دون ما رُدّ منها ينتج دَيناً سالباً لا معنى له.
        $repaid = (float) $advance->repayments()->whereNull('cancelled_at')->sum('amount');

        // [M2] سلفة رُدّ منها مال: يُمنع تغيير الإطار أو النوع
        if ($repaid > 0) {
            if ($request->filled('employee_id')) {
                $sameEmployee = (int) $request->input('employee_id') === (int) $advance->employee_id;
                if ($sameEmployee === false) {
                    return response()->json(['message' => 'لا يمكن نقل سلفة رُدّ منها مال إلى إطار آخر'], 422);
                }
            }
            if ($request->filled('type')) {
                $sameType = $request->input('type') === $advance->type;
                if ($sameType === false) {
                    return response()->json(['message' => 'لا يمكن تغيير نوع سلفة رُدّ منها مال (تسبقة/سلفة)'], 422);
                }
            }
        }
        if ($request->filled('amount') && (float) $request->input('amount') < $repaid) {
            return response()->json([
                'message' => 'المبلغ الجديد أقلّ ممّا رُدّ من السلفة ('.number_format($repaid, 2, '.', '').')',
            ], 422);
        }

        $data = $request->validate([
            'employee_id' => ['sometimes', 'integer', 'exists:employees,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'type' => ['sometimes', 'string', 'in:advance,loan'],
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'advance_date' => ['sometimes', 'date'],
            'method' => ['nullable', 'string', 'max:50'],
            'reason' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string'],
        ]);

        $advance = DB::transaction(function () use ($advance, $data) {
            $advance->update($data);
            $fresh = $advance->fresh();

            if (! $fresh->is_opening) {
                $this->ledger->recordEmployeeAdvance($fresh);
            }

            $fresh->recalculateSettlement();

            return $fresh->fresh();
        });

        return response()->json($advance->load(['employee:id,first_name,last_name', 'academicYear:id,name']));
    }

    /** سجلّ ردّيات سلفة واحدة. */
    public function repayments(EmployeeAdvance $advance): JsonResponse
    {
        return response()->json(
            $advance->repayments()
                ->with(['createdBy:id,first_name,last_name', 'cancelledBy:id,first_name,last_name'])
                ->orderBy('repaid_at')
                ->orderBy('id')
                ->get()
        );
    }

    /**
     * خلاص جزئي أو كلّي لسلفة (loan) تُردّ على مهل.
     *
     * كل ردّ يُسجّل سطراً مستقلاً بتاريخه، ولطريقة الردّ أثر محاسبي مختلف:
     *   cash             → مال دخل الدرج فعلاً → دخل في بند خلاص السلفة
     *   salary_deduction → لا مال دخل، بل سينقُص راتب الشهر → لا أثر في الدفتر هنا
     *
     * التسبقة (advance) لا تُخلّص من هنا: خلاصها يتمّ حتماً بخصمها من الراتب،
     * وفتح بابَين لنفس العملية يُنتج خلاصين لدَين واحد.
     */
    public function settle(Request $request, EmployeeAdvance $advance): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'repaid_at' => ['nullable', 'date'],
            'method' => ['nullable', 'string', 'in:cash,salary_deduction'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        if ($advance->cancelled_at) {
            return response()->json(['message' => 'لا يمكن خلاص سلفة ملغاة'], 422);
        }

        if ($advance->type === EmployeeAdvance::TYPE_ADVANCE) {
            return response()->json([
                'message' => 'التسبقة تُخصم من الراتب عند خلاصه، ولا تُخلّص من هنا',
            ], 422);
        }

        $userId = $request->user()?->id;

        try {
            $repayment = DB::transaction(function () use ($advance, $data, $userId) {
                // القفل يمنع ردّين متزامنين يتجاوز مجموعهما المتبقّي.
                $locked = EmployeeAdvance::whereKey($advance->getKey())->lockForUpdate()->firstOrFail();

                $repaid = (float) $locked->repayments()->whereNull('cancelled_at')->sum('amount');
                $remaining = round((float) $locked->amount - $repaid, 2);

                if ((float) $data['amount'] > $remaining) {
                    throw new RuntimeException(
                        'المبلغ ('.number_format((float) $data['amount'], 2, '.', '').') يتجاوز المتبقّي من السلفة ('.number_format($remaining, 2, '.', '').')'
                    );
                }

                $repayment = EmployeeAdvanceRepayment::create([
                    'employee_advance_id' => $locked->id,
                    'employee_id' => $locked->employee_id,
                    'academic_year_id' => $locked->academic_year_id,
                    'amount' => number_format((float) $data['amount'], 2, '.', ''),
                    'repaid_at' => $data['repaid_at'] ?? now()->toDateString(),
                    'method' => $data['method'] ?? EmployeeAdvanceRepayment::METHOD_CASH,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $userId,
                ]);

                $this->ledger->recordAdvanceRepayment($repayment);

                $locked->recalculateSettlement();

                return $repayment;
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'repayment' => $repayment->fresh(),
            'advance' => $advance->fresh()->load(['employee:id,first_name,last_name']),
        ], 201);
    }

    /**
     * إلغاء ردّ مسجّل خطأً: يُسحب أثره من الدفتر ويُعاد احتساب المتبقّي.
     * لا حذف نهائياً حتّى يبقى مسار التدقيق مقروءاً.
     */
    public function cancelRepayment(Request $request, EmployeeAdvanceRepayment $repayment): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($repayment->cancelled_at) {
            return response()->json(['message' => 'هذا الردّ ملغى مسبقاً'], 422);
        }

        if ($repayment->salary_id !== null) {
            return response()->json([
                'message' => 'هذا الردّ خُصم ضمن راتب؛ ألغِ الراتب أوّلاً',
            ], 422);
        }

        DB::transaction(function () use ($repayment, $data, $request) {
            $repayment->update([
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()?->id,
                'cancellation_reason' => $data['reason'],
            ]);

            $this->ledger->cancelFor($repayment, $request->user()?->id, $data['reason']);

            $repayment->advance?->recalculateSettlement();
        });

        return response()->json([
            'repayment' => $repayment->fresh(),
            'advance' => $repayment->advance?->fresh(),
        ]);
    }

    public function cancel(Request $request, EmployeeAdvance $advance): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($advance->cancelled_at) {
            return response()->json(['message' => 'هذه السلفة ملغاة مسبقاً'], 422);
        }

        if ($advance->settled_by_salary_id !== null) {
            return response()->json([
                'message' => 'هذه التسبقة خُصمت من راتب؛ ألغِ الراتب أوّلاً',
            ], 422);
        }

        // سلفة رُدّ منها مال فعلاً لا تُلغى: إلغاؤها يترك ردّيات بلا دَين تقابلها.
        $repaid = (float) $advance->repayments()->whereNull('cancelled_at')->sum('amount');

        if ($repaid > 0) {
            return response()->json([
                'message' => 'هذه السلفة رُدّ منها '.number_format($repaid, 2, '.', '').'؛ ألغِ الردّيات أوّلاً',
            ], 422);
        }

        DB::transaction(function () use ($advance, $data, $request) {
            $advance->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()?->id,
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
