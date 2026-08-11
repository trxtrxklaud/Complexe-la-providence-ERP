<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Section;
use App\Services\CollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class UnpaidMonthlyReportController extends Controller
{
    private const MONTH_NAMES_AR = [
        '01' => 'جانفي', '02' => 'فيفري', '03' => 'مارس', '04' => 'أفريل',
        '05' => 'ماي', '06' => 'جوان', '07' => 'جويلية', '08' => 'أوت',
        '09' => 'سبتمبر', '10' => 'أكتوبر', '11' => 'نوفمبر', '12' => 'ديسمبر',
    ];

    public function __construct(private readonly CollectionService $collectionService) {}

    public function options(Request $request): JsonResponse
    {
        $yearId = $request->integer('academic_year_id');
        $year = $yearId
            ? AcademicYear::findOrFail($yearId)
            : AcademicYear::query()->where('is_active', true)->orderByDesc('start_date')->first();

        return response()->json([
            'years' => AcademicYear::query()
                ->orderByDesc('start_date')
                ->get(['id', 'name', 'start_date', 'end_date', 'is_active']),
            'selected_year_id' => $year?->id,
            'months' => $year ? $this->academicYearMonths($year) : [],
            'sections' => $this->sectionOptions($year),
        ]);
    }

    /**
     * كل أقسام المدرسة من الروضة إلى السادسة، لا الأقسام التي بها تسجيلات فقط.
     *
     * النسخة السابقة كانت ترشّح بـ whereHas('enrollments')، فيختفي من قائمة
     * المتخلفين كل قسم لم يُرسَّم فيه أحد بعد في السنة المختارة. قائمة اختيار
     * لا يجوز أن تُرشَّح ببيانات المعاملات: المستعمل يحتاج القسم ليفحصه، لا بعد
     * أن يمتلئ. عدد التلاميذ يُعاد كحقل مستقل ليظهر القسم الفارغ فارغاً بصدق.
     */
    private function sectionOptions(?AcademicYear $year): array
    {
        return Section::query()
            ->with('level:id,name,code')
            ->withCount(['enrollments as students_count' => fn ($query) => $query
                ->where('academic_year_id', $year?->id ?? 0)
                ->where('status', 'active')])
            ->get(['id', 'level_id', 'name'])
            ->sortBy([
                // الروضة والتمهيدي والتحضيري أولاً، ثم الأولى إلى السادسة، ثم حرف القسم.
                fn (Section $a, Section $b) => $this->levelRank($a) <=> $this->levelRank($b),
                fn (Section $a, Section $b) => ($a->level_id ?? 0) <=> ($b->level_id ?? 0),
                fn (Section $a, Section $b) => ($a->name ?? '') <=> ($b->name ?? ''),
            ])
            ->values()
            ->map(fn (Section $section) => [
                'id' => $section->id,
                'name' => $section->name,
                'level' => $section->level?->name,
                'label' => trim(($section->level?->name ? $section->level->name.' ' : '').$section->name),
                'students_count' => (int) ($section->students_count ?? 0),
            ])
            ->all();
    }

    /** الروضة والتمهيدي والتحضيري أولاً (0)، ثم بقية المستويات (1). */
    private function levelRank(Section $section): int
    {
        $code = (string) ($section->level?->code ?? '');

        return str_starts_with($code, 'PRE') ? 0 : 1;
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'section_id' => ['required', 'integer', 'exists:sections,id'],
        ]);

        $year = AcademicYear::findOrFail($data['academic_year_id']);
        $section = Section::with('level:id,name')->findOrFail($data['section_id']);
        $months = collect($this->academicYearMonths($year))->pluck('value');

        if (! $months->contains($data['month'])) {
            throw ValidationException::withMessages([
                'month' => ['الشهر المحدد لا ينتمي إلى السنة الدراسية المختارة.'],
            ]);
        }

        $paymentMorph = (new Payment())->getMorphClass();
        $monthEnd = Carbon::createFromFormat('Y-m-d', $data['month'].'-01')->endOfMonth()->toDateString();
        $paidEnrollmentIds = Payment::query()
            ->whereNull('payments.cancelled_at')
            ->whereJsonContains('payments.months', $data['month'])
            ->whereExists(function ($query) use ($paymentMorph) {
                $query->selectRaw('1')
                    ->from('cash_transactions')
                    ->whereColumn('cash_transactions.source_id', 'payments.id')
                    ->where('cash_transactions.source_type', $paymentMorph)
                    ->where('cash_transactions.category', CashTransaction::CATEGORY_MONTHLY_FEE)
                    ->whereNull('cash_transactions.cancelled_at');
            })
            ->pluck('payments.enrollment_id')
            ->filter()
            ->unique();

        $enrollments = Enrollment::query()
            ->where('academic_year_id', $year->id)
            ->where('section_id', $section->id)
            ->where('status', 'active')
            ->whereDate('enrollment_date', '<=', $monthEnd)
            ->whereNotIn('id', $paidEnrollmentIds)
            ->with(['student.guardians' => fn ($query) => $query
                ->orderByDesc('guardian_student.is_primary_contact')])
            ->get()
            ->filter(fn (Enrollment $enrollment) => $enrollment->student !== null)
            ->unique('student_id')
            ->sortBy(fn (Enrollment $enrollment) => $this->normalizeName(
                $enrollment->student->first_name.' '.$enrollment->student->last_name
            ), SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->map(function (Enrollment $enrollment) {
                $student = $enrollment->student;
                $guardian = $student->guardians->first();

                // الأب والأم مفصولان عمداً: الواجهة تحتاج حجب كل حقل على حدة
                // عند الطباعة والتوزيع، والحقل المدمج لا يُمكّن من ذلك.
                $fatherName = trim(implode(' ', array_filter([
                    $guardian?->first_name ?? $student->guardian_first_name,
                    $guardian?->last_name ?? $student->guardian_last_name,
                ])));
                $fatherPhone = $guardian?->phone ?? $student->guardian_phone;
                $motherName = trim((string) ($student->mother_name ?? ''));

                return [
                    'enrollment_id' => $enrollment->id,
                    'student_id' => $student->id,
                    'student_code' => $student->student_code,
                    'student_name' => trim($student->first_name.' '.$student->last_name),
                    // يُحافَظ على الحقلين القديمين حتى لا تنكسر أي واجهة تقرأهما.
                    'guardian_name' => $fatherName,
                    'phone' => $fatherPhone ?? $student->mother_phone,
                    'father_name' => $fatherName !== '' ? $fatherName : null,
                    'father_phone' => $fatherPhone,
                    'mother_name' => $motherName !== '' ? $motherName : null,
                    'mother_phone' => $student->mother_phone,
                    'enrollment_date' => $enrollment->enrollment_date?->toDateString(),
                ];
            });

        $generatedAt = now();

        return response()->json([
            'school_name' => config('app.name'),
            'title' => 'قائمة التلاميذ غير المسددين للقسط الشهري',
            'academic_year' => ['id' => $year->id, 'name' => $year->name],
            'month' => ['value' => $data['month'], 'label' => $this->monthLabel($data['month'])],
            'section' => [
                'id' => $section->id,
                'name' => $section->name,
                'level' => $section->level?->name,
                'label' => trim(($section->level?->name ? $section->level->name.' ' : '').$section->name),
            ],
            'generated_at' => $generatedAt->toIso8601String(),
            'report_date' => $generatedAt->toDateString(),
            'report_time' => $generatedAt->format('H:i:s'),
            'rows' => $enrollments,
            'summary' => ['unpaid_students_count' => $enrollments->count()],
        ]);
    }

    private function academicYearMonths(AcademicYear $year): array
    {
        return array_map(
            fn (string $value) => ['value' => $value, 'label' => $this->monthLabel($value)],
            $this->collectionService->getAcademicYearMonths($year),
        );
    }

    private function monthLabel(string $month): string
    {
        return (self::MONTH_NAMES_AR[substr($month, 5, 2)] ?? $month).' '.substr($month, 0, 4);
    }

    private function normalizeName(string $name): string
    {
        $name = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', trim($name)) ?? $name;
        $name = str_replace(['أ', 'إ', 'آ', 'ى', 'ة'], ['ا', 'ا', 'ا', 'ي', 'ه'], $name);

        return mb_strtolower(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }
}
