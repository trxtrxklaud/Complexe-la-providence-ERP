<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeLiabilityRequest;
use App\Models\AcademicYear;
use App\Models\EmployeeLiability;
use App\Models\Salary;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * مستحقات الإطارات القديمة (بيانات خارجية بلا أثر سابق في النظام).
 *
 * الإدخال لا يحرّك مالاً. الخلاص يمرّ بالدفتر النقدي كبند مستقل
 * (old_liability_payment) عبر راتب تسجيلي مرتبط بالاستحقاق — لا كصرف
 * للسنة الحالية حتّى لا ينحرف «أجور العام» بتكاليف سنوات سابقة.
 */
class EmployeeLiabilityController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function index(Request $request): JsonResponse
    {
        $items = EmployeeLiability::with([
            'employee:id,first_name,last_name,job_title',
            'academicYear:id,name',
            'createdBy:id,first_name,last_name',
            'cancelledBy:id,first_name,last_name',
        ])
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('academic_year_id'), fn ($q) => $q->where('academic_year_id', $request->integer('academic_year_id')))
            ->when($request->filled('liability_type'), fn ($q) => $q->where('liability_type', $request->input('liability_type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->boolean('outstanding'), fn ($q) => $q->whereNull('cancelled_at'))
            ->when($request->boolean('exclude_cancelled'), fn ($q) => $q->whereNull('cancelled_at'))
            ->latest('id')
            ->paginate(min($request->integer('per_page', 20), 100));

        $items->through(function (EmployeeLiability $liability) {
            $liability->setAttribute('paid_amount', $liability->paid());
            $liability->setAttribute('outstanding_amount', $liability->outstanding());

            return $liability;
        });

        return response()->json($items);
    }

    /**
     * إدخال استحقاق قديم يدوياً — بدون أي أثر نقدي.
     */
    public function store(StoreEmployeeLiabilityRequest $request): JsonResponse
    {
        $data = $request->validated();

        $yearId = $data['academic_year_id'] ?? AcademicYear::where('is_active', true)->value('id');

        if (! $yearId) {
            return response()->json(['message' => 'لا توجد سنة دراسية نشطة؛ حدِّد السنة الدراسية'], 422);
        }

        $data['academic_year_id'] = (int) $yearId;
        $data['created_by'] = $request->user()?->id;
        $data['status'] = EmployeeLiability::STATUS_PENDING;

        // نوع الاستحقاق مقابل تصنيف الإطار تحقّق منه StoreEmployeeLiabilityRequest.

        $liability = EmployeeLiability::create($data);

        return response()->json($liability->load([
            'employee:id,first_name,last_name,job_title',
            'academicYear:id,name',
        ]), 201);
    }

    public function show(EmployeeLiability $liability): JsonResponse
    {
        $liability->load([
            'employee:id,first_name,last_name,job_title',
            'academicYear:id,name',
            'createdBy:id,first_name,last_name',
            'cancelledBy:id,first_name,last_name',
            'salaries' => fn ($q) => $q->orderBy('paid_at')->orderBy('id'),
            'advances' => fn ($q) => $q->orderBy('advance_date')->orderBy('id'),
        ]);

        $liability->setAttribute('paid_amount', $liability->paid());
        $liability->setAttribute('outstanding_amount', $liability->outstanding());

        return response()->json($liability);
    }

    /**
     * خلاص استحقاق قديم: راتب تسجيلي مرتبط بالاستحقاق يُسقط في الدفتر كبند
     * مستقل (old_liability_payment) فيخرج من الخزينة من غير أن يُحتسب أجراً
     * للسنة الحالية. الإلغاء يتمّ عبر مسار إلغاء الرواتب المعتاد.
     */
    public function pay(Request $request, EmployeeLiability $liability): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'paid_at' => ['nullable', 'date'],
            'method' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        if ($liability->isCancelled()) {
            return response()->json(['message' => 'لا يمكن خلاص استحقاق ملغى'], 422);
        }

        $userId = $request->user()?->id;

        try {
            $salary = DB::transaction(function () use ($liability, $data, $userId) {
                // القفل يمنع خلاصين متزامنين يتجاوز مجموعهما المتبقّي.
                $locked = EmployeeLiability::whereKey($liability->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->outstanding() < (float) $data['amount']) {
                    throw new RuntimeException(
                        'المبلغ ('.number_format((float) $data['amount'], 2, '.', '')
                        .') يتجاوز المتبقّي من الاستحقاق ('.number_format($locked->outstanding(), 2, '.', '').')'
                    );
                }

                $paidAt = $data['paid_at'] ?? now()->toDateString();

                $salary = Salary::create([
                    'employee_id' => $locked->employee_id,
                    'academic_year_id' => $locked->academic_year_id,
                    'gross_amount' => number_format((float) $data['amount'], 2, '.', ''),
                    'advance_deduction' => 0,
                    'amount' => number_format((float) $data['amount'], 2, '.', ''),
                    'period_from' => $paidAt,
                    'period_to' => $paidAt,
                    'paid_at' => $paidAt,
                    'method' => $data['method'] ?? 'cash',
                    'reference' => $data['reference'] ?? null,
                    'notes' => 'خلاص مستحقّ سابق: '.$locked->description.($data['notes'] ? ' — '.$data['notes'] : ''),
                    'created_by' => $userId,
                ]);

                // عمود الربط خارج fillable عمداً — يُضبط مباشرة ليبقى الرابط
                // حصرياً لهذا المسار (خلاص المستحقّات القديمة).
                $salary->employee_liability_id = $locked->id;
                $salary->save();

                $this->ledger->recordLiabilityPayment($salary, $locked);

                $locked->update([
                    'status' => $locked->fresh()->paid() >= (float) $locked->original_amount
                        ? EmployeeLiability::STATUS_PAID
                        : EmployeeLiability::STATUS_PARTIAL,
                ]);

                return $salary;
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'salary' => $salary->fresh()->load(['employee:id,first_name,last_name', 'academicYear:id,name']),
            'liability' => $liability->fresh()->load(['employee:id,first_name,last_name', 'academicYear:id,name']),
        ], 201);
    }

    /**
     * حفظ التزامات قديمة لعدة إطارات دفعة واحدة (سطر لكل إطار له مبلغ > 0).
     *
     * قواعد:
     *  - سنة المنشأ لا تطابق السنة النشطة.
     *  - liability_type يطابق staff_type (عاملة دين فقط، البقية دين/سلفة).
     *  - إطار له التزام قائم يُحدَّث (لا ازدواج)، ويُمنع إن حُصّل منه جزء.
     *  - التسجيل لا ينشئ cash_transaction ولا يستخدم old_liability_payment.
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'original_year_label' => ['required', 'string', 'max:20'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.employee_id' => ['required', 'integer', 'distinct', 'exists:employees,id'],
            'items.*.liability_type' => ['required', 'string', 'in:'.implode(',', EmployeeLiability::LIABILITY_TYPES)],
            'items.*.amount' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $yearId = $data['academic_year_id'] ?? AcademicYear::where('is_active', true)->value('id');
        if (! $yearId) {
            return response()->json(['message' => 'لا توجد سنة دراسية نشطة؛ حدِّد السنة الدراسية'], 422);
        }
        $targetYear = AcademicYear::findOrFail($yearId);
        if (trim($data['original_year_label']) === $targetYear->name) {
            return response()->json([
                'message' => 'سنة المنشأ ('.$data['original_year_label'].') لا يمكن أن تساوي السنة الدراسية الحالية',
            ], 422);
        }

        $userId = $request->user()?->id;
        $description = 'التزام قديم — إدخال جماعي ('.$data['original_year_label'].')';

        // خريطة staff_type → الأنواع المسموحة (مطابقة لـ StoreEmployeeLiabilityRequest)
        $allowedFor = fn (?string $type): array => match ($type) {
            'worker' => ['debt'],
            'hourly_teacher', 'monthly_teacher', 'club_animator' => ['debt', 'advance'],
            default => ['debt'],
        };

        try {
            $result = DB::transaction(function () use ($data, $targetYear, $userId, $description, $allowedFor) {
                $created = 0;
                $updated = 0;

                $employees = \App\Models\Employee::whereIn('id', array_map(fn ($i) => (int) $i['employee_id'], $data['items']))
                    ->get(['id', 'staff_type'])
                    ->keyBy('id');

                foreach ($data['items'] as $item) {
                    $amount = round((float) $item['amount'], 2);
                    if ($amount <= 0) {
                        continue;
                    }
                    $empId = (int) $item['employee_id'];
                    $emp = $employees->get($empId);
                    if (! $emp) {
                        throw new RuntimeException('الإطار رقم '.$empId.' غير موجود');
                    }
                    $allowed = $allowedFor($emp->staff_type);
                    if (! in_array($item['liability_type'], $allowed, true)) {
                        throw new RuntimeException('نوع الالتزام غير مسموح للإطار رقم '.$empId.' (نوعه '.$emp->staff_type.')');
                    }

                    $itemData = [
                        'employee_id' => $empId,
                        'academic_year_id' => (int) $targetYear->id,
                        'original_year_label' => $data['original_year_label'],
                        'liability_type' => $item['liability_type'],
                        'description' => $description,
                        'original_amount' => $amount,
                        'notes' => $item['notes'] ?? null,
                    ];

                    /** @var EmployeeLiability|null $existing */
                    $existing = EmployeeLiability::where('employee_id', $empId)
                        ->where('academic_year_id', $targetYear->id)
                        ->whereNull('cancelled_at')
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        if ($existing->paid() > 0) {
                            throw new RuntimeException(
                                'التزام الإطار رقم '.$empId.' حُصّل منه '.number_format($existing->paid(), 2, '.', '').'؛ عدّله من قائمة الالتزامات لا بالإدخال الجماعي'
                            );
                        }
                        $existing->update([
                            'original_year_label' => $itemData['original_year_label'],
                            'liability_type' => $itemData['liability_type'],
                            'original_amount' => $amount,
                            'notes' => $itemData['notes'],
                        ]);
                        $updated++;
                        continue;
                    }

                    EmployeeLiability::create([
                        ...$itemData,
                        'status' => EmployeeLiability::STATUS_PENDING,
                        'created_by' => $userId,
                    ]);
                    $created++;
                }
                return ['created' => $created, 'updated' => $updated];
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'تم حفظ التزامات الإطارات: '.$result['created'].' جديداً، '.$result['updated'].' محدَّثاً',
            'created' => $result['created'],
            'updated' => $result['updated'],
        ], 201);
    }

    /**
     * إلغاء استحقاق مُدخل خطأً — لا حذف نهائي. يُمنع بعد أي خلاص:
     * إلغاء الاستحقاق بلا إلغاء الراتب الذي خلّصه يبخّر المال.
     */
    public function cancel(Request $request, EmployeeLiability $liability): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($liability->isCancelled()) {
            return response()->json(['message' => 'هذا الاستحقاق ملغى مسبقاً'], 422);
        }

        if ($liability->paid() > 0) {
            return response()->json([
                'message' => 'هذا الاستحقاق خُلّص منه '.number_format($liability->paid(), 2, '.', '').'؛ ألغِ الرواتب/السلف المرتبطة به أوّلاً',
            ], 422);
        }

        DB::transaction(function () use ($liability, $data, $request) {
            $liability->update([
                'status' => EmployeeLiability::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()?->id,
                'cancellation_reason' => $data['reason'],
            ]);
        });

        return response()->json($liability->fresh()->load([
            'employee:id,first_name,last_name',
            'cancelledBy:id,first_name,last_name',
        ]));
    }
}
