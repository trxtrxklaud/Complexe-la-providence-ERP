<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Employee;
use App\Models\EmployeeLiability;
use App\Models\Enrollment;
use App\Models\Level;
use App\Models\ManualStudentDebt;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use App\Services\CollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * الديون القديمة المدخلة يدوياً (بيانات خارجية بلا رسم سابق في النظام).
 *
 * إدخال الدَّين لا يحرّك مالاً إطلاقاً — لا أثر له في الدفتر النقدي. المال
 * يتحرّك فقط يوم تحصيل الدَّين عبر مسار متخلّدات السنوات السابقة نفسه
 * (prior_allocations.manual_student_debt_id في عملية الاستخلاص).
 *
 * كل دَين ينشئ رسمَين جسراً تحت تسجيل التلميذ: توزيعات الدفع تشير حتماً إلى
 * student_fee، ويصنّف الدفتر القبض دَين سنة سابقة من اختلاف سنة التسجيل عن
 * سنة الدفعة. التخصيص يحمل الهدف الصريح (manual_student_debt_id) إلى جانب
 * student_fee_id حفاظاً على الفصل بين ديون التلميذ المتشابهة.
 */
class ManualDebtController extends Controller
{
    public function __construct(private readonly CollectionService $collectionService) {}

    /**
     * تحصيل دَين قديم — يعيد استخدام CollectionService::collect() كاملاً:
     * نفس الحرسان (الهدف الصريح، منع التجاوز بالقفل)، نفس تصنيف prior_year_debt،
     * ولا علاقة بأي شهر دراسي.
     */
    public function collect(Request $request, ManualStudentDebt $debt): JsonResponse
    {
        if ($debt->isCancelled()) {
            return response()->json(['message' => 'لا يمكن تحصيل دَين ملغى'], 422);
        }

        if ($debt->outstanding() <= 0) {
            return response()->json(['message' => 'هذا الدَّين مسدّد بالكامل'], 422);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'payment_date' => ['nullable', 'date'],
            'method' => ['nullable', 'string', 'in:cash,bank_transfer,check,card'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        // تسجيل الدَّين يكون في السنة التي نُقل إليها؛ التحصيل يتطلب تسجيلاً نشطاً فيها.
        $enrollment = Enrollment::where('student_id', $debt->student_id)
            ->where('academic_year_id', $debt->academic_year_id)
            ->where('status', 'active')
            ->first();

        if (! $enrollment) {
            return response()->json(['message' => 'التلميذ غير مسجّل نشط في السنة الدراسية المحمول إليها الدَّين'], 422);
        }

        try {
            $receipt = $this->collectionService->collect([
                'student_id' => $debt->student_id,
                'enrollment_id' => $enrollment->id,
                'months' => [],
                'items' => [],
                'club_items' => [],
                'prior_allocations' => [[
                    'manual_student_debt_id' => $debt->getKey(),
                    'amount' => round((float) $data['amount'], 2),
                ]],
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'method' => $data['method'] ?? 'cash',
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'idempotency_key' => $request->header('Idempotency-Key') ?: null,
            ], (int) $request->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $fresh = $debt->fresh();

        return response()->json([
            'message' => 'تم تحصيل الدَّين القديم بنجاح',
            'receipt' => $receipt,
            'debt' => [
                'id' => $fresh->id,
                'status' => $fresh->status,
                'original_amount' => (float) $fresh->original_amount,
                'collected_amount' => $fresh->collected(),
                'outstanding_amount' => $fresh->outstanding(),
            ],
        ], 201);
    }

    /** سجلّ دفعات الدَّين: كل تخصيص مع وصلته وحالتها. */
    public function payments(ManualStudentDebt $debt): JsonResponse
    {
        $rows = $debt->paymentAllocations()
            ->with('payment')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($alloc) => [
                'allocation_id' => $alloc->id,
                'payment_id' => $alloc->payment?->id,
                'payment_date' => $alloc->payment?->payment_date?->toDateString(),
                'method' => $alloc->payment?->method,
                'amount' => (float) $alloc->amount_allocated,
                'status' => $alloc->payment?->cancelled_at ? 'cancelled' : 'active',
                'cancelled_at' => $alloc->payment?->cancelled_at,
                'cancellation_reason' => $alloc->payment?->cancellation_reason,
                'created_by' => $alloc->payment?->created_by,
            ])
            ->values();

        $active = $rows->where('status', 'active')->sum('amount');
        $cancelled = $rows->where('status', 'cancelled')->sum('amount');

        return response()->json([
            'debt_id' => $debt->id,
            'payments' => $rows,
            'totals' => [
                'paid_active' => round($active, 2),
                'cancelled' => round($cancelled, 2),
                'count' => $rows->count(),
            ],
        ]);
    }

    /** بيانات «كشف استخلاص متخلد قديم» للطباعة والمراجعة. */
    public function statement(ManualStudentDebt $debt): JsonResponse
    {
        $debt->load(['student:id,first_name,last_name,student_code', 'academicYear:id,name']);

        $enrollment = Enrollment::query()
            ->with(['level:id,name', 'section:id,name'])
            ->where('student_id', $debt->student_id)
            ->where('academic_year_id', $debt->academic_year_id)
            ->orderByDesc('id')
            ->first();

        $paymentsResponse = $this->payments($debt);
        $paymentsData = $paymentsResponse->getData(true);

        return response()->json([
            'debt' => [
                'id' => $debt->id,
                'type' => 'student',
                'debt_type' => $debt->debt_type,
                'description' => $debt->description,
                'student_name' => trim(($debt->student?->first_name ?? '').' '.($debt->student?->last_name ?? '')) ?: '—',
                'student_code' => $debt->student?->student_code,
                'level' => $enrollment?->level?->name,
                'section' => $enrollment?->section?->name,
                'original_year_label' => $debt->original_year_label,
                'created_at' => $debt->created_at?->toIso8601String(),
                'original_amount' => (float) $debt->original_amount,
                'paid_amount' => $debt->collected(),
                'outstanding_amount' => $debt->outstanding(),
                'status' => $debt->status,
            ],
            'payments' => $paymentsData['payments'] ?? [],
            'totals' => $paymentsData['totals'] ?? ['paid_active' => 0, 'cancelled' => 0, 'count' => 0],
        ]);
    }

    /** ملخص الديون القديمة النشطة لتلميذ واحد (للواجهة والتنبيه). */
    public function summary(Student $student): JsonResponse
    {
        $models = ManualStudentDebt::query()
            ->with('academicYear:id,name')
            ->whereNull('cancelled_at')
            ->where('student_id', $student->id)
            ->orderByDesc('id')
            ->get();

        $debts = $models->map(fn (ManualStudentDebt $d) => [
            'id' => $d->id,
            'type' => 'student',
            'debt_type' => $d->debt_type,
            'description' => $d->description,
            'original_year_label' => $d->original_year_label,
            'created_at' => $d->created_at?->toIso8601String(),
            'academic_year' => $d->academicYear?->name,
            'original_amount' => (float) $d->original_amount,
            'collected_amount' => $d->collected(),
            'outstanding_amount' => $d->outstanding(),
            'status' => $d->status,
        ])->values();

        return response()->json([
            'student_id' => $student->id,
            'items' => $debts,
            'totals' => [
                'count' => $models->count(),
                'original_amount' => round($models->sum(fn (ManualStudentDebt $d) => (float) $d->original_amount), 2),
                'collected_amount' => round($models->sum(fn (ManualStudentDebt $d) => $d->collected()), 2),
                'outstanding_amount' => round($models->sum(fn (ManualStudentDebt $d) => $d->outstanding()), 2),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $items = ManualStudentDebt::with([
            'student:id,first_name,last_name,student_code',
            'academicYear:id,name',
            'createdBy:id,first_name,last_name',
            'cancelledBy:id,first_name,last_name',
        ])
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('academic_year_id'), fn ($q) => $q->where('academic_year_id', $request->integer('academic_year_id')))
            ->when($request->filled('debt_type'), fn ($q) => $q->where('debt_type', $request->input('debt_type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->boolean('exclude_cancelled'), fn ($q) => $q->whereNull('cancelled_at'))
            ->latest('id')
            ->paginate(min($request->integer('per_page', 20), 100));

        $items->through(function (ManualStudentDebt $debt) {
            $debt->setAttribute('collected_amount', $debt->collected());
            $debt->setAttribute('outstanding_amount', $debt->outstanding());

            return $debt;
        });

        return response()->json($items);
    }

    /**
     * إدخال دَين قديم يدوياً — بدون أي أثر نقدي.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'original_year_label' => ['required', 'string', 'max:20'],
            'debt_type' => ['required', 'string', 'in:'.implode(',', ManualStudentDebt::DEBT_TYPES)],
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
        $data['status'] = ManualStudentDebt::STATUS_PENDING;

        // K2: منع ازدواج الدَّين الفردي — دَين نشط واحد لنفس التلميذ/السنة/النوع.
        $duplicate = ManualStudentDebt::query()
            ->where('student_id', $data['student_id'])
            ->where('academic_year_id', $data['academic_year_id'])
            ->where('debt_type', $data['debt_type'])
            ->whereNull('cancelled_at')
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'يوجد دَين قديم نشط بنفس النوع لهذا التلميذ في هذه السنة الدراسية؛ عدِّله من القائمة بدل إنشاء نسخة مكررة',
            ], 422);
        }

        try {
            $debt = DB::transaction(function () use ($data) {
                $targetYear = AcademicYear::findOrFail($data['academic_year_id']);

                // آخر تسجيل سابق (تنتهي سنته قبل بداية سنة النقل) — جسر الدَّين.
                $priorEnrollment = Enrollment::where('student_id', $data['student_id'])
                    ->with('academicYear')
                    ->get()
                    ->filter(fn (Enrollment $enrollment) => $enrollment->academicYear?->end_date < $targetYear->start_date)
                    ->sortByDesc(fn (Enrollment $enrollment) => $enrollment->academicYear->end_date)
                    ->first();

                if (! $priorEnrollment) {
                    throw new RuntimeException(
                        'لا يوجد تسجيل سابق لهذا التلميذ لنقل الدَّين إليه؛ استعمل متخلّدات سنوات سابقة عبر إقفال السنة'
                    );
                }

                $bridgeFee = $this->createBridgeFee(
                    $data,
                    $priorEnrollment->id,
                    (string) $priorEnrollment->academicYear->start_date
                );

                return $this->createDebtRecord($data, $bridgeFee->id);
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($debt->load([
            'student:id,first_name,last_name,student_code',
            'academicYear:id,name',
            'sourceStudentFee:id,enrollment_id,description,amount_due,due_date,status',
        ]), 201);
    }

    public function show(ManualStudentDebt $debt): JsonResponse
    {
        $debt->load([
            'student:id,first_name,last_name,student_code',
            'academicYear:id,name',
            'sourceStudentFee:id,enrollment_id,description,amount_due,due_date,status',
            'createdBy:id,first_name,last_name',
            'cancelledBy:id,first_name,last_name',
        ]);

        $debt->setAttribute('collected_amount', $debt->collected());
        $debt->setAttribute('outstanding_amount', $debt->outstanding());

        return response()->json($debt);
    }

    /**
     * إلغاء دَين مُدخل خطأً — لا حذف نهائي حتّى يبقى مسار التدقيق مقروءاً.
     * يُمنع الإلغاء بعد تحصيل أي جزء: إلغاء الدَّين بلا ردّ ماله يبخّر المال.
     */
    public function cancel(Request $request, ManualStudentDebt $debt): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($debt->isCancelled()) {
            return response()->json(['message' => 'هذا الدَّين ملغى مسبقاً'], 422);
        }

        $collected = $debt->collected();
        if ($collected > 0) {
            return response()->json([
                'message' => 'هذا الدَّين حُصّل منه '.number_format($collected, 2, '.', '').'؛ لا يمكن إلغاؤه، ألغِ الدفعات المرتبطة به أوّلاً',
            ], 422);
        }

        DB::transaction(function () use ($debt, $data, $request) {
            $userId = $request->user()?->id;

            $debt->update([
                'status' => ManualStudentDebt::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => $data['reason'],
            ]);

            // الرسم الجسر بلا أي تحصيل → يُمحى: لا مال تحته ولا توزيع يشير إليه.
            $debt->sourceStudentFee?->delete();
        });

        return response()->json($debt->fresh()->load([
            'student:id,first_name,last_name',
            'cancelledBy:id,first_name,last_name',
        ]));
    }

    // ==================== الإدخال الجماعي ====================

    /**
     * خيارات الإدخال الجماعي: المستويات والأقسام والإطارات النشطة والسنة
     * النشطة، مع مستحقّات الإطارات القائمة هذه السنة (لتعبئة الجدول مسبقاً).
     *
     * مسار مستقل تحت manage_treasury لأن /levels و /sections و /employees
     * محمية بصلاحيات أخرى لا يملكها صاحب الخزينة.
     */
    public function bulkOptions(): JsonResponse
    {
        $activeYear = AcademicYear::where('is_active', true)->first();

        $existingLiabilities = EmployeeLiability::query()
            ->when($activeYear, fn ($q) => $q->where('academic_year_id', $activeYear->id))
            ->whereNull('cancelled_at')
            ->get()
            ->map(fn (EmployeeLiability $l) => [
                'id' => $l->id,
                'employee_id' => $l->employee_id,
                'liability_type' => $l->liability_type,
                'original_amount' => (float) $l->original_amount,
                'paid_amount' => $l->paid(),
                'outstanding_amount' => $l->outstanding(),
                'notes' => $l->notes,
                'status' => $l->status,
                'original_year_label' => $l->original_year_label,
                'created_at' => $l->created_at?->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'active_year' => $activeYear ? [
                'id' => $activeYear->id,
                'name' => $activeYear->name,
                'start_date' => $activeYear->start_date,
            ] : null,
            'levels' => Level::orderBy('order')->get(['id', 'name']),
            'sections' => Section::orderBy('name')->get(['id', 'name', 'level_id']),
            'employees' => Employee::where('is_active', true)
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name', 'job_title', 'staff_type']),
            'existing_liabilities' => $existingLiabilities,
        ]);
    }

    /**
     * تلاميذ قسم في سنة دراسية (النشطة افتراضياً) مع دَينهم القديم القائم
     * إن وُجد — لتعبئة سطور الجدول مسبقاً بلا ازدواج.
     */
    public function sectionStudents(Request $request): JsonResponse
    {
        $data = $request->validate([
            'section_id' => ['required', 'integer', 'exists:sections,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
        ]);

        $yearId = $data['academic_year_id'] ?? AcademicYear::where('is_active', true)->value('id');

        if (! $yearId) {
            return response()->json(['message' => 'لا توجد سنة دراسية نشطة'], 422);
        }

        $students = Enrollment::query()
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->where('enrollments.academic_year_id', $yearId)
            ->where('enrollments.section_id', $data['section_id'])
            ->where('enrollments.status', 'active')
            ->whereNull('enrollments.deleted_at')
            ->orderBy('students.first_name')
            ->orderBy('students.last_name')
            ->get([
                'students.id',
                'students.first_name',
                'students.last_name',
                'students.student_code',
            ]);

        $existingDebts = ManualStudentDebt::query()
            ->where('academic_year_id', $yearId)
            ->whereNull('cancelled_at')
            ->whereIn('student_id', $students->pluck('id'))
            ->get()
            ->keyBy('student_id');

        $rows = $students->map(fn ($student) => [
            'id' => $student->id,
            'full_name' => trim($student->first_name.' '.$student->last_name),
            'student_code' => $student->student_code,
            'existing' => $existingDebts->has($student->id) ? [
                'id' => $existingDebts[$student->id]->id,
                'debt_type' => $existingDebts[$student->id]->debt_type,
                'original_amount' => (float) $existingDebts[$student->id]->original_amount,
                'notes' => $existingDebts[$student->id]->notes,
                'collected_amount' => $existingDebts[$student->id]->collected(),
            ] : null,
        ])->values();

        return response()->json([
            'academic_year_id' => (int) $yearId,
            'students' => $rows,
        ]);
    }

    /**
     * حفظ ديون قديمة لعدة تلاميذ دفعة واحدة (سطر لكل تلميذ له مبلغ > 0).
     *
     * قواعد:
     *  - سنة المنشأ إجبارية ولا تطابق السنة الدراسية الحالية.
     *  - تلميذ له دَين قائم هذه السنة يُحدَّث سطره (لا ازدواج)، ويُمنع
     *    التحديث إن حُصّل منه جزء.
     *  - الجسر رسم حرّ تحت تسجيل التلميذ الحالي بتاريخ استحقاق = بداية
     *    السنة؛ الدفتر يصنّف قبضه متخلّدات لكونه دَيناً يدوياً مدخولاً.
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'original_year_label' => ['required', 'string', 'max:20'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.student_id' => ['required', 'integer', 'distinct', 'exists:students,id'],
            'items.*.debt_type' => ['required', 'string', 'in:'.implode(',', ManualStudentDebt::DEBT_TYPES)],
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
        $description = 'ديون قديمة — إدخال جماعي ('.$data['original_year_label'].')';

        try {
            $result = DB::transaction(function () use ($data, $targetYear, $userId, $description) {
                $created = 0;
                $updated = 0;

                $enrollments = Enrollment::query()
                    ->where('academic_year_id', $targetYear->id)
                    ->whereIn('student_id', array_map(fn ($item) => (int) $item['student_id'], $data['items']))
                    ->get()
                    ->keyBy('student_id');

                foreach ($data['items'] as $item) {
                    $amount = round((float) $item['amount'], 2);

                    // المبلغ صفر أو أقلّ → لا سجلّ لهذا التلميذ.
                    if ($amount <= 0) {
                        continue;
                    }

                    $studentId = (int) $item['student_id'];
                    $enrollment = $enrollments->get($studentId);

                    if (! $enrollment) {
                        throw new RuntimeException(
                            'التلميذ رقم '.$studentId.' غير مسجّل في السنة '.$targetYear->name
                        );
                    }

                    $itemData = [
                        'student_id' => $studentId,
                        'academic_year_id' => (int) $targetYear->id,
                        'original_year_label' => $data['original_year_label'],
                        'debt_type' => $item['debt_type'],
                        'description' => $description,
                        'original_amount' => $amount,
                        'notes' => $item['notes'] ?? null,
                    ];

                    /** @var ManualStudentDebt|null $existing */
                    $existing = ManualStudentDebt::query()
                        ->where('student_id', $studentId)
                        ->where('academic_year_id', $targetYear->id)
                        ->whereNull('cancelled_at')
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        if ($existing->collected() > 0) {
                            throw new RuntimeException(
                                'دَين التلميذ رقم '.$studentId.' حُصّل منه '.number_format($existing->collected(), 2, '.', '').'؛ عدِّله من قائمة الديون لا بالإدخال الجماعي'
                            );
                        }

                        $existing->update([
                            'original_year_label' => $itemData['original_year_label'],
                            'debt_type' => $itemData['debt_type'],
                            'original_amount' => $amount,
                            'notes' => $itemData['notes'],
                        ]);
                        $existing->sourceStudentFee?->update([
                            'amount_due' => number_format($amount, 2, '.', ''),
                        ]);
                        $updated++;

                        continue;
                    }

                    $bridgeFee = $this->createBridgeFee($itemData, $enrollment->id, (string) $targetYear->start_date);
                    $this->createDebtRecord($itemData, $bridgeFee->id, $userId);
                    $created++;
                }

                return ['created' => $created, 'updated' => $updated];
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'تم حفظ ديون التلاميذ: '.$result['created'].' جديداً، '.$result['updated'].' محدَّثاً',
            'created' => $result['created'],
            'updated' => $result['updated'],
        ], 201);
    }

    // ==================== الدوال المساعدة ====================

    /**
     * الرسم الجسر: حرّ (بلا معلیم/نوع/نادٍ) تحت تسجيل محدَّد.
     * توزيعات الدفع تشير حتماً إلى student_fee، والدفتر يصنّف قبض الجسر
     * دَيناً قديماً اعتماداً على ارتباطه بسجلّ دَين يدوي.
     */
    private function createBridgeFee(array $data, int $enrollmentId, string $dueDate): StudentFee
    {
        return StudentFee::create([
            'enrollment_id' => $enrollmentId,
            'fee_plan_id' => null,
            'fee_type_id' => null,
            'club_monthly_fee_id' => null,
            'description' => 'دَين قديم: '.$data['description'],
            'amount_due' => number_format((float) $data['original_amount'], 2, '.', ''),
            'due_date' => $dueDate,
            'status' => 'pending',
        ]);
    }

    private function createDebtRecord(array $data, int $bridgeFeeId, ?int $userId = null): ManualStudentDebt
    {
        return ManualStudentDebt::create([
            ...$data,
            'source_student_fee_id' => $bridgeFeeId,
            'status' => ManualStudentDebt::STATUS_PENDING,
            'created_by' => $userId,
        ]);
    }
}
