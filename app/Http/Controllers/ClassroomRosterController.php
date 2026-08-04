<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Section;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * كشف مداخيل القسم — مرآة الجدول الورقي القديم: سطر لكل تلميذ في القسم
 * مرتّبين أبجدياً، مفصّلاً على بنود المداخيل، وأمام كل تلميذ ما تخلّد بذمّته.
 *
 * فرق جوهري عن /reports/revenue/classrooms/{section}: ذاك يبدأ من الدفعات
 * فلا يرى إلا من دفع. هذا يبدأ من التسجيلات فيرى كل التلاميذ، ومن لم يدفع
 * يظهر بأصفار وبمتخلَّده كاملاً — وهو أهمّ سطر في الكشف عملياً.
 *
 * قاعدتان لا استثناء لهما:
 * 1) كل مبلغ مقبوض يُقرأ من cash_transactions لا من payments، فلا يختلف رقم
 *    هذه الصفحة عن الخزينة ولا عن الدخل الصافي عند أول إلغاء.
 * 2) المتخلَّد يُحسب من student_fees ناقص payment_allocations للوصولات غير
 *    الملغاة. هو التزام لا حركة نقدية، فلا مكان له في الدفتر أصلاً.
 */
class ClassroomRosterController extends Controller
{
    /**
     * قائمة الأقسام للقائمة المنسدلة.
     *
     * مسار مستقل تحت view_reports عمداً: /levels و /sections محروسان بـ
     * manage_users، فلو قرأت الشاشة منهما لرأى المحاسب قائمة فارغة و 403.
     */
    public function options(): JsonResponse
    {
        $sections = Section::query()
            ->with('level:id,name,order')
            ->get(['id', 'level_id', 'name'])
            ->sortBy(fn (Section $section) => sprintf(
                '%03d-%s',
                $section->level?->order ?? 999,
                $section->name ?? ''
            ))
            ->values()
            ->map(fn (Section $section) => [
                'id'       => $section->id,
                'name'     => $section->name,
                'level'    => $section->level?->name,
                'level_id' => $section->level_id,
                'label'    => trim(($section->level?->name ? $section->level->name . ' ' : '') . $section->name),
            ]);

        return response()->json([
            'sections' => $sections,
            'years'    => AcademicYear::query()
                ->orderByDesc('start_date')
                ->get(['id', 'name', 'start_date', 'end_date', 'is_active']),
            'active_year_id' => AcademicYear::query()
                ->where('is_active', true)
                ->orderByDesc('start_date')
                ->value('id'),
        ]);
    }

    public function index(Request $request, Section $section): JsonResponse
    {
        $data = $request->validate([
            'date_from'        => ['nullable', 'date'],
            'date_to'          => ['nullable', 'date', 'after_or_equal:date_from'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
        ]);

        $year = ! empty($data['academic_year_id'])
            ? AcademicYear::find($data['academic_year_id'])
            : AcademicYear::query()->where('is_active', true)->orderByDesc('start_date')->first();

        $section->load('level:id,name');

        $enrollments = Enrollment::query()
            ->where('section_id', $section->id)
            ->when($year !== null, fn ($q) => $q->where('academic_year_id', $year->id))
            ->with('student:id,student_code,first_name,last_name,guardian_phone')
            ->get(['id', 'student_id', 'enrollment_date'])
            ->filter(fn (Enrollment $enrollment) => $enrollment->student !== null)
            ->unique('student_id');

        $paid        = $this->paidByStudent($section, $year, $data);
        $outstanding = $this->outstandingByStudent($section, $year);

        $categories = CashTransaction::INCOME_CATEGORIES;
        $rows       = [];
        $seen       = [];

        foreach ($enrollments as $enrollment) {
            $studentId    = (int) $enrollment->student_id;
            $seen[]       = $studentId;
            $rows[]       = $this->row(
                studentId: $studentId,
                code: $enrollment->student->student_code,
                name: trim($enrollment->student->first_name . ' ' . $enrollment->student->last_name),
                phone: $enrollment->student->guardian_phone,
                enrolled: true,
                paid: $paid[$studentId] ?? [],
                outstanding: $outstanding[$studentId] ?? 0.0,
                categories: $categories,
            );
        }

        // من دفع تحت هذا القسم ثم لم يعد في تسجيلاته (نقلة أو حذف تسجيل):
        // يُضاف حتى يبقى مجموع الأسطر مساوياً لمجموع الكشف. إسقاطه يجعل
        // الجدول لا يجمع إلى مجموعه، وهو أسوأ خطأ يمكن أن يحمله تقرير مالي.
        foreach ($paid as $studentId => $line) {
            if (in_array($studentId, $seen, true)) {
                continue;
            }

            $rows[] = $this->row(
                studentId: (int) $studentId,
                code: $line['student_code'] ?? null,
                name: $line['name'] ?? '—',
                phone: null,
                enrolled: false,
                paid: $line,
                outstanding: $outstanding[$studentId] ?? 0.0,
                categories: $categories,
            );
        }

        usort($rows, fn (array $a, array $b) => strcmp($a['sort_key'], $b['sort_key']));

        $byCategory = [];
        foreach ($categories as $category) {
            $byCategory[] = [
                'category' => $category,
                'label'    => CashTransaction::CATEGORY_LABELS[$category] ?? $category,
                'total'    => round(array_sum(array_column(array_column($rows, 'by_category'), $category)), 2),
            ];
        }

        $generatedAt = now();

        return response()->json([
            'filters' => $data,
            'section' => [
                'id'    => $section->id,
                'name'  => $section->name,
                'level' => $section->level?->name,
                'label' => trim(($section->level?->name ? $section->level->name . ' ' : '') . $section->name),
            ],
            'academic_year' => $year ? ['id' => $year->id, 'name' => $year->name] : null,
            'categories'    => array_map(fn (string $category) => [
                'category' => $category,
                'label'    => CashTransaction::CATEGORY_LABELS[$category] ?? $category,
            ], $categories),
            'by_category' => $byCategory,
            'rows'        => $rows,
            'summary'     => [
                'students_count'    => count($rows),
                'enrolled_count'    => count($seen),
                'payers_count'      => count(array_filter($rows, fn (array $r) => $r['total'] > 0)),
                'debtors_count'     => count(array_filter($rows, fn (array $r) => $r['outstanding'] > 0)),
                'total'             => round(array_sum(array_column($rows, 'total')), 2),
                'outstanding_total' => round(array_sum(array_map(
                    fn (array $r) => max($r['outstanding'], 0.0),
                    $rows
                )), 2),
            ],
            'report_date' => $generatedAt->toDateString(),
            'report_time' => $generatedAt->format('H:i'),
        ]);
    }

    // ==================== الدوال المساعدة ====================

    /**
     * @param  array<string,float|string|null>  $paid
     * @param  array<int,string>  $categories
     * @return array<string,mixed>
     */
    private function row(
        int $studentId,
        ?string $code,
        string $name,
        ?string $phone,
        bool $enrolled,
        array $paid,
        float $outstanding,
        array $categories,
    ): array {
        $lines = [];
        foreach ($categories as $category) {
            $lines[$category] = round((float) ($paid[$category] ?? 0.0), 2);
        }

        return [
            'student_id'     => $studentId,
            'student_code'   => $code,
            'name'           => $name,
            'phone'          => $phone,
            'enrolled'       => $enrolled,
            'payments_count' => (int) ($paid['payments_count'] ?? 0),
            'by_category'    => $lines,
            'total'          => round(array_sum($lines), 2),
            'outstanding'    => round($outstanding, 2),
            'sort_key'       => $this->normalizeName($name),
        ];
    }

    /**
     * المقبوض لكل تلميذ مفصّلاً على البنود، من الدفتر النقدي حصراً.
     *
     * الربط بالسنة يمرّ عبر enrollments.academic_year_id لا عبر
     * cash_transactions.academic_year_id: عمود الدفتر مشتقّ ويمكن أن يكون
     * قديماً في أسطر أُنشئت قبل ضبط السنة النشطة، أمّا سنة التسجيل فهي الحقيقة.
     *
     * @param  array<string,mixed>  $data
     * @return array<int,array<string,mixed>>
     */
    private function paidByStudent(Section $section, ?AcademicYear $year, array $data): array
    {
        $rows = DB::table('cash_transactions as ct')
            ->join('payments as p', 'p.id', '=', 'ct.source_id')
            ->join('students as s', 's.id', '=', 'p.student_id')
            ->join('enrollments as e', 'e.id', '=', 'p.enrollment_id')
            ->where('ct.source_type', (new Payment)->getMorphClass())
            ->whereNull('ct.cancelled_at')
            ->whereIn('ct.category', CashTransaction::INCOME_CATEGORIES)
            ->where('e.section_id', $section->id)
            ->when($year !== null, fn ($q) => $q->where('e.academic_year_id', $year->id))
            ->when(
                ! empty($data['date_from']),
                fn ($q) => $q->whereDate('ct.transaction_date', '>=', $data['date_from'])
            )
            ->when(
                ! empty($data['date_to']),
                fn ($q) => $q->whereDate('ct.transaction_date', '<=', $data['date_to'])
            )
            ->groupBy('s.id', 's.student_code', 's.first_name', 's.last_name', 'ct.category')
            ->select([
                's.id as student_id',
                's.student_code',
                's.first_name',
                's.last_name',
                'ct.category',
            ])
            ->selectRaw('SUM(ct.amount) as total')
            ->selectRaw('COUNT(DISTINCT p.id) as payments_count')
            ->get();

        $paid = [];

        foreach ($rows as $row) {
            $studentId = (int) $row->student_id;

            if (! isset($paid[$studentId])) {
                $paid[$studentId] = [
                    'student_code'   => $row->student_code,
                    'name'           => trim($row->first_name . ' ' . $row->last_name),
                    'payments_count' => 0,
                ];
            }

            $paid[$studentId][$row->category] = round((float) $row->total, 2);
            $paid[$studentId]['payments_count'] += (int) $row->payments_count;
        }

        return $paid;
    }

    /**
     * المتخلَّد بالذمّة لكل تلميذ: مجموع الرسوم المستحقّة ناقص ما وُزِّع عليها
     * من وصولات غير ملغاة. الوصل الملغى يعيد الدين تلقائياً لأن توزيعاته تُستثنى.
     *
     * @return array<int,float>
     */
    private function outstandingByStudent(Section $section, ?AcademicYear $year): array
    {
        $due = DB::table('student_fees as f')
            ->join('enrollments as e', 'e.id', '=', 'f.enrollment_id')
            ->where('e.section_id', $section->id)
            ->when($year !== null, fn ($q) => $q->where('e.academic_year_id', $year->id))
            ->groupBy('e.student_id')
            ->select('e.student_id')
            ->selectRaw('SUM(f.amount_due) as due')
            ->pluck('due', 'student_id');

        $allocated = DB::table('payment_allocations as pa')
            ->join('student_fees as f', 'f.id', '=', 'pa.student_fee_id')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->join('enrollments as e', 'e.id', '=', 'f.enrollment_id')
            ->whereNull('p.cancelled_at')
            ->where('e.section_id', $section->id)
            ->when($year !== null, fn ($q) => $q->where('e.academic_year_id', $year->id))
            ->groupBy('e.student_id')
            ->select('e.student_id')
            ->selectRaw('SUM(pa.amount_allocated) as paid')
            ->pluck('paid', 'student_id');

        $outstanding = [];

        foreach ($due as $studentId => $amount) {
            $outstanding[(int) $studentId] = round(
                (float) $amount - (float) ($allocated[$studentId] ?? 0),
                2
            );
        }

        return $outstanding;
    }

    /**
     * ترتيب أبجدي عربي سليم: التشكيل والتطويل يُحذفان، والهمزات وصور الياء
     * والتاء تُوحَّد، وإلا تفرّق «أحمد» عن «احمد» في القائمة نفسها.
     */
    private function normalizeName(string $name): string
    {
        $name = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', trim($name)) ?? $name;
        $name = str_replace(['أ', 'إ', 'آ', 'ى', 'ة'], ['ا', 'ا', 'ا', 'ي', 'ه'], $name);

        return mb_strtolower(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }
}
