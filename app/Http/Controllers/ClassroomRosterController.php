<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\Payment;
use App\Models\Section;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * كشف مداخيل القسم — مرآة الجدول الورقي القديم: سطر لكل تلميذ في القسم
 * مرتّبين أبجدياً، مفصّلاً على بنود المداخيل وعلى أشهر السنة شهراً شهراً.
 *
 * فرق جوهري عن /reports/revenue/classrooms/{section}: ذاك يبدأ من الدفعات
 * فلا يرى إلا من دفع. هذا يبدأ من التسجيلات فيرى كل التلاميذ.
 *
 * ثلاث قواعد لا استثناء لها:
 * 1) كل مبلغ مقبوض يُقرأ من cash_transactions لا من payments، فلا يختلف رقم
 *    هذه الصفحة عن الخزينة ولا عن الدخل الصافي عند أول إلغاء.
 * 2) حالة الشهر تُقرأ من payments.months حصراً — وهو ما اختاره القابض بيده،
 *    لا تاريخ القبض. من دفع في جانفي شهر ديسمبر يُحتسب لديسمبر.
 * 3) المتخلّد الشهري = عدد الأشهر المنقضية غير المدفوعة × القسط المرجعي
 *    لمستوى القسم (FeePlan). لا يُحمّل التلميذ شهراً لم يأتِ دوره.
 */
class ClassroomRosterController extends Controller
{
    /** السنة الدراسية عشرة أشهر: سبتمبر → جوان. مطابق لـ CollectionService. */
    private const SCHOOL_MONTHS = [9, 10, 11, 12, 1, 2, 3, 4, 5, 6];

    private const MONTH_NAMES_AR = [
        '01' => 'جانفي', '02' => 'فيفري', '03' => 'مارس',
        '04' => 'أفريل', '05' => 'ماي', '06' => 'جوان',
        '07' => 'جويلية', '08' => 'أوت', '09' => 'سبتمبر',
        '10' => 'أكتوبر', '11' => 'نوفمبر', '12' => 'ديسمبر',
    ];

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

        $activeYear = AcademicYear::query()
            ->where('is_active', true)
            ->orderByDesc('start_date')
            ->first();

        return response()->json([
            'sections' => $sections,
            'years'    => AcademicYear::query()
                ->orderByDesc('start_date')
                ->get(['id', 'name', 'start_date', 'end_date', 'is_active']),
            'active_year_id' => $activeYear?->id,
            'months'         => $activeYear ? $this->academicYearMonths($activeYear) : [],
        ]);
    }

    public function index(Request $request, Section $section): JsonResponse
    {
        $data = $request->validate([
            'date_from'        => ['nullable', 'date'],
            'date_to'          => ['nullable', 'date', 'after_or_equal:date_from'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'month_from'       => ['nullable', 'string', 'regex:/^\\d{4}-\\d{2}$/'],
            'month_to'         => ['nullable', 'string', 'regex:/^\\d{4}-\\d{2}$/'],
        ], [
            'date_to.after_or_equal' => 'تاريخ النهاية يجب أن يكون بعد تاريخ البداية أو مساوياً له',
            'academic_year_id.exists' => 'السنة الدراسية المختارة غير موجودة',
            'month_from.regex' => 'صيغة شهر البداية غير صحيحة (المطلوب: YYYY-MM)',
            'month_to.regex'   => 'صيغة شهر النهاية غير صحيحة (المطلوب: YYYY-MM)',
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
        $monthDefs   = $this->monthColumns($year, $data);
        $monthsPaid  = $this->paidMonthsByStudent($section, $year);
        $reference   = $this->referenceMonthlyFee($section, $year);

        $categories = CashTransaction::INCOME_CATEGORIES;
        $rows       = [];
        $seen       = [];

        foreach ($enrollments as $enrollment) {
            $studentId = (int) $enrollment->student_id;
            $seen[]    = $studentId;
            $rows[]    = $this->row(
                studentId: $studentId,
                code: $enrollment->student->student_code,
                name: trim($enrollment->student->first_name . ' ' . $enrollment->student->last_name),
                phone: $enrollment->student->guardian_phone,
                enrolled: true,
                paid: $paid[$studentId] ?? [],
                outstanding: $outstanding[$studentId] ?? 0.0,
                categories: $categories,
                monthDefs: $monthDefs,
                studentMonths: $monthsPaid[$studentId] ?? [],
                reference: $reference,
            );
        }

        // من دفع تحت هذا القسم ثم لم يعد في تسجيلاته (نقلة أو حذف تسجيل):
        // يُضاف حتى يبقى مجموع الأسطر مساوياً لمجموع الكشف.
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
                monthDefs: $monthDefs,
                studentMonths: $monthsPaid[$studentId] ?? [],
                reference: $reference,
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

        // مجاميع أسفل كل عمود شهر: كم دفع وكم تخلّف في ذاك الشهر.
        $byMonth = [];
        foreach ($monthDefs as $index => $def) {
            $cells = array_column(array_column($rows, 'months'), $index);
            $byMonth[] = [
                'key'        => $def['key'],
                'label'      => $def['label'],
                'paid_count' => count(array_filter($cells, fn ($c) => $c['status'] === 'paid')),
                'late_count' => count(array_filter($cells, fn ($c) => $c['status'] === 'late')),
                'due_count'  => count(array_filter($cells, fn ($c) => $c['status'] === 'due')),
                'total'      => round(array_sum(array_column($cells, 'amount')), 2),
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
            'months'                 => $monthDefs,
            'reference_monthly_fee'  => $reference,
            'by_category'            => $byCategory,
            'by_month'               => $byMonth,
            'rows'                   => $rows,
            'summary'     => [
                'students_count'    => count($rows),
                'enrolled_count'    => count($seen),
                'payers_count'      => count(array_filter($rows, fn (array $r) => $r['total'] > 0)),
                'debtors_count'     => count(array_filter($rows, fn (array $r) => $r['late_count'] > 0)),
                'total'             => round(array_sum(array_column($rows, 'total')), 2),
                'months_arrears'    => round(array_sum(array_column($rows, 'months_arrears')), 2),
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
     * أعمدة الأشهر المعروضة مع تصنيف زمني لكل شهر مقارنةً باليوم:
     * منقضٍ (يستحقّ وجوباً)، جارٍ (ما زالت أيامه)، أو لم يأتِ بعد.
     *
     * @param  array<string,mixed>  $data
     * @return array<int,array<string,mixed>>
     */
    private function monthColumns(?AcademicYear $year, array $data): array
    {
        if ($year === null) {
            return [];
        }

        $today = Carbon::today();
        $from  = $data['month_from'] ?? null;
        $to    = $data['month_to'] ?? null;

        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        $columns = [];

        foreach ($this->academicYearMonths($year) as $month) {
            if ($from !== null && $month['key'] < $from) {
                continue;
            }
            if ($to !== null && $month['key'] > $to) {
                continue;
            }

            $start = Carbon::parse($month['key'] . '-01');
            $end   = $start->copy()->endOfMonth();

            $columns[] = [
                'key'     => $month['key'],
                'label'   => $month['label'],
                'year'    => $month['year'],
                'elapsed' => $end->lt($today),
                'current' => $start->lte($today) && $end->gte($today),
            ];
        }

        return $columns;
    }

    /**
     * أشهر السنة الدراسية بمفاتيح YYYY-MM مرتّبة دراسياً لا تقويمياً.
     *
     * @return array<int,array<string,mixed>>
     */
    private function academicYearMonths(AcademicYear $year): array
    {
        $startYear = (int) Carbon::parse($year->start_date)->format('Y');
        $months    = [];

        foreach (self::SCHOOL_MONTHS as $month) {
            $calendarYear = $month >= 9 ? $startYear : $startYear + 1;
            $key          = sprintf('%04d-%02d', $calendarYear, $month);

            $months[] = [
                'key'   => $key,
                'label' => self::MONTH_NAMES_AR[sprintf('%02d', $month)] ?? $key,
                'year'  => $calendarYear,
            ];
        }

        return $months;
    }

    /**
     * الأشهر المدفوعة لكل تلميذ من payments.months.
     *
     * لا يُرشّح هنا بتاريخ القبض عمداً: تغطية الشهر حقيقة ثابتة لا تتغير
     * بتغيير مدّة التقرير؛ من دفع ديسمبر في جانفي يبقى ديسمبر مدفوعاً.
     * مبلغ الشهر = قسط الدفعة الشهري في الدفتر ÷ عدد أشهرها (تقسيم متساوٍ
     * معلن صراحةً، لأن الوصل لا يخزّن حصّة كل شهر منفردة).
     *
     * @return array<int,array<string,array<string,mixed>>>
     */
    private function paidMonthsByStudent(Section $section, ?AcademicYear $year): array
    {
        $rows = DB::table('payments as p')
            ->join('enrollments as e', 'e.id', '=', 'p.enrollment_id')
            ->leftJoin('cash_transactions as ct', function ($join) {
                $join->on('ct.source_id', '=', 'p.id')
                    ->where('ct.source_type', '=', (new Payment)->getMorphClass())
                    ->where('ct.category', '=', CashTransaction::CATEGORY_MONTHLY_FEE)
                    ->whereNull('ct.cancelled_at');
            })
            ->whereNull('p.cancelled_at')
            ->whereNotNull('p.months')
            ->where('e.section_id', $section->id)
            ->when($year !== null, fn ($q) => $q->where('e.academic_year_id', $year->id))
            ->groupBy('e.student_id', 'p.id', 'p.months', 'p.payment_date')
            ->select(['e.student_id', 'p.id as payment_id', 'p.months', 'p.payment_date'])
            ->selectRaw('COALESCE(SUM(ct.amount), 0) as monthly_amount')
            ->get();

        $paidMonths = [];

        foreach ($rows as $row) {
            $months = json_decode((string) $row->months, true);

            if (! is_array($months) || $months === []) {
                continue;
            }

            $share     = round(((float) $row->monthly_amount) / count($months), 2);
            $studentId = (int) $row->student_id;

            foreach ($months as $month) {
                $paidMonths[$studentId][(string) $month] = [
                    'amount'       => $share,
                    'payment_id'   => (int) $row->payment_id,
                    'payment_date' => $row->payment_date
                        ? Carbon::parse($row->payment_date)->toDateString()
                        : null,
                ];
            }
        }

        return $paidMonths;
    }

    /**
     * القسط الشهري المرجعي لمستوى القسم من FeePlan.
     * أساس حساب متخلّد الأشهر؛ إن لم يُعرّف المخطّط أُرجع 0
     * وتُعرض الحالات بالألوان بلا مبلغ مخترع.
     */
    private function referenceMonthlyFee(Section $section, ?AcademicYear $year): float
    {
        if ($year === null || $section->level_id === null) {
            return 0.0;
        }

        return round((float) FeePlan::query()
            ->where('academic_year_id', $year->id)
            ->where('level_id', $section->level_id)
            ->where('frequency', 'monthly')
            ->sum('amount'), 2);
    }

    /**
     * @param  array<string,float|string|null>  $paid
     * @param  array<int,string>  $categories
     * @param  array<int,array<string,mixed>>  $monthDefs
     * @param  array<string,array<string,mixed>>  $studentMonths
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
        array $monthDefs,
        array $studentMonths,
        float $reference,
    ): array {
        $lines = [];
        foreach ($categories as $category) {
            $lines[$category] = round((float) ($paid[$category] ?? 0.0), 2);
        }

        $cells     = [];
        $lateCount = 0;
        $unpaid    = [];

        foreach ($monthDefs as $def) {
            $entry = $studentMonths[$def['key']] ?? null;

            if ($entry !== null) {
                $status = 'paid';
            } elseif ($def['elapsed']) {
                $status = 'late';
                $lateCount++;
                $unpaid[] = $def['label'];
            } elseif ($def['current']) {
                $status = 'due';
            } else {
                $status = 'upcoming';
            }

            $cells[] = [
                'key'          => $def['key'],
                'label'        => $def['label'],
                'status'       => $status,
                'amount'       => round((float) ($entry['amount'] ?? 0.0), 2),
                'payment_date' => $entry['payment_date'] ?? null,
            ];
        }

        return [
            'student_id'     => $studentId,
            'student_code'   => $code,
            'name'           => $name,
            'phone'          => $phone,
            'enrolled'       => $enrolled,
            'payments_count' => (int) ($paid['payments_count'] ?? 0),
            'by_category'    => $lines,
            'months'         => $cells,
            'paid_months'    => count(array_filter($cells, fn (array $c) => $c['status'] === 'paid')),
            'late_count'     => $lateCount,
            'unpaid_months'  => $unpaid,
            'months_arrears' => round($lateCount * $reference, 2),
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
     * المتخلّد المحاسبي: مجموع الرسوم المستحقّة ناقص ما وُزّع عليها
     * من وصولات غير ملغاة. الوصل الملغى يعيد الدين تلقائياً.
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
     * والتاء تُوحّد، وإلا تفرّق «أحمد» عن «احمد» في القائمة نفسها.
     */
    private function normalizeName(string $name): string
    {
        $name = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', trim($name)) ?? $name;
        $name = str_replace(['أ', 'إ', 'آ', 'ى', 'ة'], ['ا', 'ا', 'ا', 'ي', 'ه'], $name);

        return mb_strtolower(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }
}
