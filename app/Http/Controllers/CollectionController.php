<?php

namespace App\Http\Controllers;

use App\Http\Requests\CollectPaymentRequest;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\ManualStudentDebt;
use App\Models\Section;
use App\Models\Student;
use App\Services\CollectionService;
use App\Services\OpeningBalanceService;
use App\Services\PaymentAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollectionController extends Controller
{
    public function __construct(
        private readonly CollectionService $collectionService,
        private readonly OpeningBalanceService $openingBalances,
        private readonly PaymentAllocationService $allocationService,
    ) {}

    public function years(): JsonResponse
    {
        $years = AcademicYear::orderByDesc('start_date')
            ->get(['id', 'name', 'is_active', 'start_date', 'end_date']);

        return response()->json($years);
    }

    /**
     * كل أقسام المدرسة، لا الأقسام التي بها تسجيلات فقط.
     *
     * النسخة السابقة كانت ترشّح بـ whereHas('enrollments')، فيختفي من شاشة
     * الاستخلاص كل قسم لم يُرسّم فيه تلميذ بعد في السنة المختارة — وهذا بالضبط
     * وضع بداية السنة الدراسية: القابض يحتاج القسم ليستخلص فيه قبل أن يمتلئ.
     *
     * الترتيب مثل المنصة القديمة: الأقسام التحضيرية أولاً، ثم الأولى إلى السادسة،
     * ثم حرف القسم (أ، ب، ج، د، هـ). الترتيب بالاسم وحده كان يخلط «أ» كل
     * المستويات معاً فيظهر الجدول وكأنه ناقص.
     */
    public function sectionsByYear(AcademicYear $year): JsonResponse
    {
        $sections = Section::with('level:id,name,code')
            ->withCount(['enrollments as students_count' => fn ($q) => $q
                ->where('academic_year_id', $year->id)
                ->where('status', 'active')])
            ->get(['id', 'level_id', 'name'])
            ->sortBy([
                // المستويات التحضيرية (PRE) قبل الابتدائية.
                fn (Section $a, Section $b) => $this->levelRank($a) <=> $this->levelRank($b),
                fn (Section $a, Section $b) => ($a->level_id ?? 0) <=> ($b->level_id ?? 0),
                fn (Section $a, Section $b) => ($a->name ?? '') <=> ($b->name ?? ''),
            ])
            ->values();

        return response()->json($sections);
    }

    /** الروضة والتمهيدي والتحضيري أولاً (0)، ثم بقية المستويات (1). */
    private function levelRank(Section $section): int
    {
        $code = (string) ($section->level?->code ?? '');

        return str_starts_with($code, 'PRE') ? 0 : 1;
    }

    public function studentsBySection(Section $section, Request $request): JsonResponse
    {
        $yearId = $request->input('year_id');

        if ($yearId !== null && $yearId !== '') {
            $request->validate([
                'year_id' => ['integer', 'exists:academic_years,id'],
            ]);
            $yearId = (int) $yearId;
        } else {
            $yearId = AcademicYear::where('is_active', true)->value('id')
                ?? AcademicYear::latest('id')->value('id');
        }

        $enrollments = Enrollment::where('section_id', $section->id)
            ->where('academic_year_id', $yearId)
            // المغادر والمنقول لا يُستخلص منهما في هذا القسم.
            ->where('status', 'active')
            ->with([
                'student:id,first_name,last_name,student_code',
                'student.guardians',
            ])
            ->get();

        $result = $enrollments->map(function ($e) {
            $guardian = $e->student?->guardians
                ?->sortByDesc(fn ($g) => $g->pivot->is_primary_contact ?? 0)
                ->first();

            return [
                'enrollment_id' => $e->id,
                'student' => [
                    'id' => $e->student->id,
                    'first_name' => $e->student->first_name,
                    'last_name' => $e->student->last_name,
                    'student_code' => $e->student->student_code,
                ],
                'guardian' => $guardian ? [
                    'first_name' => $guardian->first_name,
                    'last_name' => $guardian->last_name,
                    'phone' => $guardian->phone,
                ] : null,
            ];
        })
            ->sortBy(fn ($row) => trim($row['student']['first_name'].' '.$row['student']['last_name']))
            ->values();

        return response()->json($result);
    }

    /**
     * قواعد التحقق موحّدة الآن في CollectPaymentRequest بدل تكرارها هنا،
     * وهي تتضمّن التحقق من أن التسجيل يخصّ التلميذ المحدَّد.
     */
    public function collect(CollectPaymentRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            // مفتاح منع التكرار: يُفضَّل ترويسة Idempotency-Key ثم حقل الطلب.
            $data['idempotency_key'] = $request->header('Idempotency-Key')
                ?: ($data['idempotency_key'] ?? null);

            $receipt = $this->collectionService->collect(
                $data,
                (int) auth()->id()
            );

            return response()->json([
                'message' => 'تم تسجيل الاستخلاص بنجاح',
                'receipt' => $receipt,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'فشل تسجيل الاستخلاص'], 500);
        }
    }

    public function ledger(Enrollment $enrollment): JsonResponse
    {
        return response()->json([
            'enrollment_id' => $enrollment->id,
            'paid_months' => $this->collectionService->getPaidMonths($enrollment->id),
            'ledger' => $this->collectionService->monthLedger($enrollment->id),
            'year_months' => $enrollment->academicYear
                ? $this->collectionService->getAcademicYearMonths($enrollment->academicYear)
                : [],
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enrollment_id' => ['required', 'integer', 'exists:enrollments,id'],
            'months' => ['required', 'array', 'min:1'],
            'months.*' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'fee_type_id' => ['nullable', 'integer', 'exists:fee_types,id'],
        ]);

        $preview = $this->collectionService->preview(
            (int) $data['enrollment_id'],
            $data['months'],
            isset($data['fee_type_id']) ? (int) $data['fee_type_id'] : null
        );

        return response()->json($preview);
    }

    /**
     * متخلّدات السنوات السابقة لتلميذ — الرصيد الافتتاحي الذي يُعرض للقابض
     * قبل القبض حتى يقرّر كيف يوزّع المبلغ بين الدَّين القديم ورسوم السنة.
     *
     * manual_debts: الديون القديمة المدخلة يدوياً (غير الملغاة وغير المدفوعة)
     * — تحصيلها يمرّ بنفس المسار (prior_allocations.manual_student_debt_id).
     */
    public function openingBalances(Student $student, Request $request): JsonResponse
    {
        $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
        ]);

        $yearId = $request->integer('academic_year_id') ?: null;

        // السنة المعروضة: المحدَّدة إن وُجدت، وإلا فالسنة النشطة.
        $manualYearId = $yearId ?? AcademicYear::where('is_active', true)->value('id');

        $manualDebts = ManualStudentDebt::query()
            ->where('student_id', $student->id)
            ->whereNull('cancelled_at')
            ->where('status', '!=', ManualStudentDebt::STATUS_PAID)
            ->when($manualYearId, fn ($q, $v) => $q->where('academic_year_id', (int) $v))
            ->get()
            ->map(fn (ManualStudentDebt $debt) => [
                'id' => $debt->id,
                'original_year_label' => $debt->original_year_label,
                'debt_type' => $debt->debt_type,
                'description' => $debt->description,
                'original_amount' => (float) $debt->original_amount,
                'outstanding' => $debt->outstanding(),
            ])
            ->values();

        return response()->json([
            'student_id' => $student->id,
            'academic_year_id' => $yearId,
            'summary' => $this->openingBalances->summaryForStudent($student, $yearId),
            'items' => $this->openingBalances->priorYearFeesForStudent($student, $yearId),
            'manual_debts' => $manualDebts,
        ]);
    }

    /**
     * معاينة توزيع مبلغ على تلميذ حسب الترتيب الافتراضي (الأقدم أولاً).
     *
     * يراها المحاسب قبل تثبيت الوصل ويعدّلها يدوياً إن شاء؛ الخادم يتحقق
     * من التوزيع الصريح النهائي عند الحفظ فلا يتجاوز متبقّي أي رسم.
     */
    public function allocationPreview(Student $student, Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
        ]);

        return response()->json(
            $this->allocationService->suggest(
                $student,
                (float) $data['amount'],
                $request->integer('academic_year_id') ?: null
            )
        );
    }
}
