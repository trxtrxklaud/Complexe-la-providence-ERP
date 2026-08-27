<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Employee;
use App\Models\OldEmployeeDebt;
use App\Models\OldEmployeeDebtCollection;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ديون الإطارات القديمة — أرصدة افتتاحية تاريخية مدخلة يدوياً.
 *
 * إدخال الدَّين قيد إداري بحت لا يحرّك مالاً في الخزينة ولا ينشئ رواتب أو سلفاً.
 * المال يتحرّك حصراً يوم تحصيل الدَّين (inflow) كـ old_liability_collection
 * في الخزينة، دون أن يدخل في الدخل التشغيلي أو صافي الدخل.
 */
class OldEmployeeDebtController extends Controller
{
    public function __construct(private readonly LedgerService $ledgerService) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min($request->integer('per_page', 20), 100));

        $items = OldEmployeeDebt::with([
            'employee:id,first_name,last_name,job_title',
            'academicYear:id,name',
            'createdBy:id,first_name,last_name',
            'cancelledBy:id,first_name,last_name',
        ])
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('academic_year_id'), fn ($q) => $q->where('academic_year_id', $request->integer('academic_year_id')))
            ->when($request->filled('debt_type'), fn ($q) => $q->where('debt_type', $request->input('debt_type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->boolean('exclude_cancelled'), fn ($q) => $q->whereNull('cancelled_at'))
            ->latest('id')
            ->paginate($perPage);

        $items->through(function (OldEmployeeDebt $debt) {
            $debt->setAttribute('collected_amount', $debt->collectedAmount());
            $debt->setAttribute('outstanding_amount', $debt->outstandingAmount());

            return $debt;
        });

        return response()->json($items);
    }

    public function show(OldEmployeeDebt $debt): JsonResponse
    {
        $debt->load([
            'employee:id,first_name,last_name,job_title',
            'academicYear:id,name',
            'createdBy:id,first_name,last_name',
            'cancelledBy:id,first_name,last_name',
        ]);

        $debt->setAttribute('collected_amount', $debt->collectedAmount());
        $debt->setAttribute('outstanding_amount', $debt->outstandingAmount());

        return response()->json($debt);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'original_year_label' => ['required', 'string', 'max:20'],
            'debt_type' => ['required', 'string', 'in:'.implode(',', OldEmployeeDebt::DEBT_TYPES)],
            'description' => ['required', 'string', 'max:255'],
            'original_amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $yearId = $data['academic_year_id'] ?? AcademicYear::where('is_active', true)->value('id');

        if (! $yearId) {
            return response()->json(['message' => 'لا توجد سنة دراسية نشطة؛ حدِّد السنة الدراسية'], 422);
        }

        $data['academic_year_id'] = (int) $yearId;
        $data['created_by'] = $request->user()?->id;
        $data['status'] = OldEmployeeDebt::STATUS_PENDING;

        return DB::transaction(function () use ($data) {
            Employee::query()
                ->whereKey($data['employee_id'])
                ->lockForUpdate()
                ->firstOrFail();

            // منع ازدواج الدَّين النشط لنفس الإطار/السنة/النوع
            $duplicate = OldEmployeeDebt::query()
                ->where('employee_id', $data['employee_id'])
                ->where('academic_year_id', $data['academic_year_id'])
                ->where('debt_type', $data['debt_type'])
                ->whereNull('cancelled_at')
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'message' => 'يوجد دَين قديم نشط بنفس النوع لهذا الإطار في هذه السنة الدراسية؛ لا يمكن تكرار الدين',
                ], 422);
            }

            $debt = OldEmployeeDebt::create($data);

            $debt->load([
                'employee:id,first_name,last_name,job_title',
                'academicYear:id,name',
            ]);
            $debt->setAttribute('collected_amount', 0.0);
            $debt->setAttribute('outstanding_amount', (float) $debt->original_amount);

            return response()->json($debt, 201);
        });
    }

    public function update(Request $request, OldEmployeeDebt $debt): JsonResponse
    {
        if ($debt->isCancelled()) {
            return response()->json(['message' => 'لا يمكن تعديل دَين ملغى'], 422);
        }

        if ($request->exists('employee_id') || $request->exists('academic_year_id')) {
            return response()->json([
                'message' => 'لا يمكن نقل الدين الافتتاحي إلى إطار أو سنة دراسية أخرى بعد إنشائه.',
            ], 422);
        }

        if ($debt->hasCollections()) {
            // بعد وجود تحصيل: يمنع تعديل أي حقل مالي أو وصفي
            $restrictedKeys = ['original_amount', 'debt_type', 'description', 'original_year_label'];
            $hasRestricted = false;
            foreach ($restrictedKeys as $key) {
                if ($request->exists($key)) {
                    $hasRestricted = true;
                    break;
                }
            }

            if ($hasRestricted) {
                return response()->json([
                    'message' => 'هذا الدَّين حُصّل منه مبالغ؛ لا يمكن تعديل الحقول المالية أو الوصف، يُسمح بتعديل الملاحظات فقط',
                ], 422);
            }

            $data = $request->validate([
                'notes' => ['nullable', 'string', 'max:2000'],
            ]);

            $debt->update([
                'notes' => $data['notes'] ?? null,
            ]);
        } else {
            $data = $request->validate([
                'original_year_label' => ['sometimes', 'required', 'string', 'max:20'],
                'debt_type' => ['sometimes', 'required', 'string', 'in:'.implode(',', OldEmployeeDebt::DEBT_TYPES)],
                'description' => ['sometimes', 'required', 'string', 'max:255'],
                'original_amount' => ['sometimes', 'required', 'numeric', 'min:0.01', 'max:1000000'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ]);

            if (isset($data['debt_type']) && $data['debt_type'] !== $debt->debt_type) {
                $duplicate = OldEmployeeDebt::query()
                    ->where('employee_id', $debt->employee_id)
                    ->where('academic_year_id', $debt->academic_year_id)
                    ->where('debt_type', $data['debt_type'])
                    ->where('id', '!=', $debt->id)
                    ->whereNull('cancelled_at')
                    ->exists();

                if ($duplicate) {
                    return response()->json([
                        'message' => 'يوجد دَين قديم نشط بهذا النوع لهذا الإطار في هذه السنة الدراسية',
                    ], 422);
                }
            }

            $debt->update($data);
        }

        $fresh = $debt->fresh([
            'employee:id,first_name,last_name,job_title',
            'academicYear:id,name',
            'createdBy:id,first_name,last_name',
            'cancelledBy:id,first_name,last_name',
        ]);
        $fresh->setAttribute('collected_amount', $fresh->collectedAmount());
        $fresh->setAttribute('outstanding_amount', $fresh->outstandingAmount());

        return response()->json($fresh);
    }

    public function cancel(Request $request, OldEmployeeDebt $debt): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($debt->isCancelled()) {
            return response()->json(['message' => 'هذا الدَّين ملغى مسبقاً'], 422);
        }

        if ($debt->hasCollections()) {
            return response()->json([
                'message' => 'هذا الدَّين حُصّل منه مبالغ؛ لا يمكن إلغاؤه',
            ], 422);
        }

        $debt->update([
            'status' => OldEmployeeDebt::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $request->user()?->id,
            'cancellation_reason' => $data['reason'],
        ]);

        $fresh = $debt->fresh([
            'employee:id,first_name,last_name,job_title',
            'cancelledBy:id,first_name,last_name',
        ]);
        $fresh->setAttribute('collected_amount', $fresh->collectedAmount());
        $fresh->setAttribute('outstanding_amount', $fresh->outstandingAmount());

        return response()->json($fresh);
    }

    public function collect(Request $request, OldEmployeeDebt $debt): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'payment_date' => ['nullable', 'date'],
            'method' => ['nullable', 'string', 'in:cash,bank_transfer,check,card'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return DB::transaction(function () use ($debt, $data, $request) {
            /** @var OldEmployeeDebt $lockedDebt */
            $lockedDebt = OldEmployeeDebt::query()
                ->where('id', $debt->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedDebt->isCancelled()) {
                return response()->json(['message' => 'لا يمكن تحصيل دَين ملغى'], 422);
            }

            $outstanding = $lockedDebt->outstandingAmount();
            if ($outstanding <= 0) {
                return response()->json(['message' => 'هذا الدَّين مسدّد بالكامل'], 422);
            }

            $amount = round((float) $data['amount'], 2);
            if ($amount > $outstanding) {
                return response()->json([
                    'message' => 'المبلغ المطلوب تحصيله ('.number_format($amount, 2, '.', '').') أكبر من المتبقي ('.number_format($outstanding, 2, '.', '').')',
                ], 422);
            }

            $paymentDate = $data['payment_date'] ?? now()->toDateString();
            $userId = (int) $request->user()?->id;

            /** @var OldEmployeeDebtCollection $collection */
            $collection = OldEmployeeDebtCollection::create([
                'employee_opening_debt_id' => $lockedDebt->id,
                'amount' => $amount,
                'payment_date' => $paymentDate,
                'method' => $data['method'] ?? 'cash',
                'notes' => $data['notes'] ?? null,
                'collected_by' => $userId ?: null,
            ]);

            $tx = $this->ledgerService->recordOldEmployeeDebtCollection($collection);

            $lockedDebt->syncStatus();

            $fresh = $lockedDebt->fresh([
                'employee:id,first_name,last_name,job_title',
                'academicYear:id,name',
            ]);
            $fresh->setAttribute('collected_amount', $fresh->collectedAmount());
            $fresh->setAttribute('outstanding_amount', $fresh->outstandingAmount());

            return response()->json([
                'message' => 'تم تحصيل دَين الإطار بنجاح',
                'collection' => $collection,
                'transaction' => $tx,
                'debt' => $fresh,
            ], 201);
        });
    }
}
