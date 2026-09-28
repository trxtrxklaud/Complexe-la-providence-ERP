<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentDiscount;
use App\Models\FeePlan;
use App\Models\MonthlyDiscount;
use App\Models\Section;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * كشف جرد التخفيضات حسب القسم والأشهر.
 *
 * يتيح لصاحب النظام (waive_fees) معاينة كشف كامل لتلاميذ القسم
 * وتخفيضاتهم المطبقة على أشهر السنة الدراسية العشرة (سبتمبر إلى جوان).
 * مع إمكانية تعديل التخفيض أو حذفه أو تخصيص شهر بعينه دون سواه دون التأثير
 * على بقية الأشهر أو السجلات المالية.
 */
class DiscountRosterController extends Controller
{
    /** السنة الدراسية عشرة أشهر: سبتمبر → جوان. */
    private const SCHOOL_MONTHS = [9, 10, 11, 12, 1, 2, 3, 4, 5, 6];

    private const MONTH_NAMES_AR = [
        '01' => 'جانفي', '02' => 'فيفري', '03' => 'مارس',
        '04' => 'أفريل', '05' => 'ماي', '06' => 'جوان',
        '07' => 'جويلية', '08' => 'أوت', '09' => 'سبتمبر',
        '10' => 'أكتوبر', '11' => 'نوفمبر', '12' => 'ديسمبر',
    ];

    /**
     * قائمة الخيارات: الأقسام والسنوات الدراسية والأشهر النشطة.
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
            'sections'       => $sections,
            'years'          => AcademicYear::query()
                ->orderByDesc('start_date')
                ->get(['id', 'name', 'start_date', 'end_date', 'is_active']),
            'active_year_id' => $activeYear?->id,
            'months'         => $activeYear ? $this->academicYearMonths($activeYear) : [],
        ]);
    }

    /**
     * كشف جرد التخفيضات لقسم معين.
     */
    public function roster(Request $request, Section $section): JsonResponse
    {
        $data = $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
        ], [
            'academic_year_id.exists' => 'السنة الدراسية المختارة غير موجودة',
        ]);

        $year = ! empty($data['academic_year_id'])
            ? AcademicYear::findOrFail($data['academic_year_id'])
            : AcademicYear::query()->where('is_active', true)->orderByDesc('start_date')->firstOrFail();

        $months = $this->academicYearMonths($year);

        // المعلوم المرجعي الشهري للمستوى من FeePlan
        $referenceMonthlyFee = (float) FeePlan::query()
            ->where('academic_year_id', $year->id)
            ->where('level_id', $section->level_id)
            ->where('frequency', 'monthly')
            ->sum('amount');

        $discountCap = round($referenceMonthlyFee * 0.20, 2);

        // جلب جميع التلاميذ المسجلين في هذا القسم
        $enrollments = Enrollment::query()
            ->where('academic_year_id', $year->id)
            ->where('section_id', $section->id)
            ->where('status', 'active')
            ->with([
                'student:id,first_name,last_name,student_code',
                'monthlyDiscounts' => fn ($q) => $q->active()
                    ->where('academic_year_id', $year->id)
                    ->where('fee_category', 'tuition')
                    ->with(['creator:id,first_name,last_name']),
                'discounts' => fn ($q) => $q->active()
                    ->where('academic_year_id', $year->id)
                    ->with(['creator:id,first_name,last_name']),
            ])
            ->get()
            ->filter(fn (Enrollment $e) => $e->student !== null)
            ->sortBy(fn (Enrollment $e) => $this->normalizeName(
                $e->student->first_name . ' ' . $e->student->last_name
            ), SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $rows = [];
        $studentsWithDiscount = 0;
        $totalDiscountAmountAll = 0.0;

        foreach ($enrollments as $enrollment) {
            $student = $enrollment->student;
            $annualDisc = $enrollment->discounts->first();
            $monthlyDiscounts = $enrollment->monthlyDiscounts;

            $monthCells = [];
            $studentDiscountTotal = 0.0;
            $hasAnyDiscount = false;

            foreach ($months as $mDef) {
                $mKey = $mDef['key'];

                // 1. التحقق من التخفيض الشهري الدوري
                $activeMonthly = $monthlyDiscounts->first(fn (MonthlyDiscount $d) => $d->coversMonth($mKey));

                if ($activeMonthly) {
                    $hasAnyDiscount = true;
                    $isFull = $activeMonthly->discount_type === MonthlyDiscount::TYPE_FULL_WAIVER;
                    $amt = $isFull ? $referenceMonthlyFee : (float) $activeMonthly->monthly_amount;
                    $studentDiscountTotal += $amt;

                    $monthCells[$mKey] = [
                        'has_discount'     => true,
                        'discount_id'      => $activeMonthly->id,
                        'discount_source'  => 'monthly',
                        'discount_type'    => $activeMonthly->discount_type,
                        'amount'           => $amt,
                        'amount_formatted' => $isFull ? 'إعفاء كلي' : number_format($amt, 2, '.', '') . ' د',
                        'reason'           => $activeMonthly->reason,
                        'notes'            => $activeMonthly->notes,
                        'start_month'      => $activeMonthly->start_month,
                        'end_month'        => $activeMonthly->end_month,
                        'is_single_month'  => $activeMonthly->start_month === $activeMonthly->end_month,
                        'created_by'       => $activeMonthly->creator ? trim($activeMonthly->creator->first_name . ' ' . $activeMonthly->creator->last_name) : null,
                    ];
                } elseif ($annualDisc && (float) $annualDisc->amount > 0) {
                    // 2. التحقق من التخفيض السنوي العادي
                    $hasAnyDiscount = true;
                    $amt = (float) $annualDisc->amount;
                    $studentDiscountTotal += $amt;

                    $monthCells[$mKey] = [
                        'has_discount'     => true,
                        'discount_id'      => $annualDisc->id,
                        'discount_source'  => 'annual',
                        'discount_type'    => 'normal',
                        'amount'           => $amt,
                        'amount_formatted' => number_format($amt, 2, '.', '') . ' د',
                        'reason'           => $annualDisc->reason,
                        'notes'            => null,
                        'start_month'      => $months[0]['key'],
                        'end_month'        => $months[count($months) - 1]['key'],
                        'is_single_month'  => false,
                        'created_by'       => $annualDisc->creator ? trim($annualDisc->creator->first_name . ' ' . $annualDisc->creator->last_name) : null,
                    ];
                } else {
                    $monthCells[$mKey] = [
                        'has_discount'     => false,
                        'discount_id'      => null,
                        'discount_source'  => null,
                        'discount_type'    => null,
                        'amount'           => 0.0,
                        'amount_formatted' => '—',
                        'reason'           => null,
                        'notes'            => null,
                        'start_month'      => null,
                        'end_month'        => null,
                        'is_single_month'  => false,
                        'created_by'       => null,
                    ];
                }
            }

            if ($hasAnyDiscount) {
                $studentsWithDiscount++;
            }
            $totalDiscountAmountAll += $studentDiscountTotal;

            $rows[] = [
                'enrollment_id'       => $enrollment->id,
                'student_id'          => $student->id,
                'student_code'        => $student->student_code,
                'name'                => trim($student->first_name . ' ' . $student->last_name),
                'monthly_fee'         => $referenceMonthlyFee,
                'discount_cap'        => $discountCap,
                'has_any_discount'    => $hasAnyDiscount,
                'total_discount'      => round($studentDiscountTotal, 2),
                'months'              => $monthCells,
            ];
        }

        return response()->json([
            'section' => [
                'id'         => $section->id,
                'name'       => $section->name,
                'level_id'   => $section->level_id,
                'level_name' => $section->level?->name,
                'label'      => trim(($section->level?->name ? $section->level->name . ' ' : '') . $section->name),
            ],
            'academic_year'         => [
                'id'         => $year->id,
                'name'       => $year->name,
                'start_date' => $year->start_date,
                'end_date'   => $year->end_date,
            ],
            'months'                => $months,
            'reference_monthly_fee' => $referenceMonthlyFee,
            'discount_cap'          => $discountCap,
            'summary'               => [
                'students_count'            => count($rows),
                'with_discount_count'       => $studentsWithDiscount,
                'without_discount_count'    => count($rows) - $studentsWithDiscount,
                'total_discount_amount'     => round($totalDiscountAmountAll, 2),
            ],
            'rows'                  => $rows,
        ]);
    }

    /**
     * إسناد أو تعديل تخفيض (شهر واحد، فترة، أو كامل السنة) مع الحفاظ الذكي على الأشهر الأخرى.
     */
    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enrollment_id'  => ['required', 'integer', 'exists:enrollments,id'],
            'scope'          => ['required', 'string', 'in:single_month,range,full_year'],
            'target_month'   => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'start_month'    => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'end_month'      => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'discount_type'  => ['required', 'string', 'in:normal_monthly,humanitarian_fixed,full_waiver'],
            'monthly_amount' => ['nullable', 'numeric', 'min:0'],
            'reason'         => ['required', 'string', 'max:500'],
            'notes'          => ['nullable', 'string', 'max:1000'],
        ], [
            'enrollment_id.required'  => 'التسجيل إجباري',
            'discount_type.required'  => 'نوع التخفيض إجباري',
            'reason.required'         => 'سبب التخفيض إجباري',
            'target_month.regex'      => 'صيغة الشهر غير صحيحة (المطلوب: YYYY-MM)',
            'start_month.regex'       => 'صيغة شهر البداية غير صحيحة',
            'end_month.regex'         => 'صيغة شهر النهاية غير صحيحة',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $enrollment = Enrollment::whereKey($data['enrollment_id'])->lockForUpdate()->firstOrFail();
            $academicYear = AcademicYear::findOrFail($enrollment->academic_year_id);

            // تحديد شهر البداية والنهاية حسب النطاق
            if ($data['scope'] === 'single_month') {
                if (empty($data['target_month'])) {
                    throw new InvalidArgumentException('الشهر المستهدف إجباري عند اختيار شهر منفرد');
                }
                $startMonth = $data['target_month'];
                $endMonth   = $data['target_month'];
            } elseif ($data['scope'] === 'range') {
                if (empty($data['start_month']) || empty($data['end_month'])) {
                    throw new InvalidArgumentException('شهرا البداية والنهاية إجباريان عند اختيار فترة');
                }
                $startMonth = min($data['start_month'], $data['end_month']);
                $endMonth   = max($data['start_month'], $data['end_month']);
            } else {
                // full_year
                $startMonth = Carbon::parse($academicYear->start_date)->format('Y-m');
                $endMonth   = Carbon::parse($academicYear->end_date)->format('Y-m');
            }

            // احتساب المعلوم الشهري والتحقق من السقوف
            $monthlyFee = (float) FeePlan::query()
                ->where('academic_year_id', $academicYear->id)
                ->where('level_id', $enrollment->level_id)
                ->where('frequency', 'monthly')
                ->sum('amount');

            if ($monthlyFee <= 0) {
                throw new InvalidArgumentException('تعذّر حساب المعلوم الشهري: لا يوجد مخطط رسوم لهذا المستوى');
            }

            $amount = isset($data['monthly_amount']) ? (float) $data['monthly_amount'] : null;

            if ($data['discount_type'] === MonthlyDiscount::TYPE_NORMAL_MONTHLY) {
                if ($amount === null || $amount <= 0) {
                    throw new InvalidArgumentException('مبلغ التخفيض الشهري يجب أن يكون أكبر من الصفر');
                }
                $maxCap = round($monthlyFee * 0.20, 2);
                if ($amount > $maxCap) {
                    throw new InvalidArgumentException("مبلغ التخفيض الشهري ({$amount} د) يتجاوز الحد الأقصى المسموح 20% ({$maxCap} د)");
                }
                $finalAmount = round($amount, 2);
            } elseif ($data['discount_type'] === MonthlyDiscount::TYPE_HUMANITARIAN_FIXED) {
                if ($amount === null || $amount <= 20.0) {
                    throw new InvalidArgumentException('مبلغ التخفيض الإنساني يجب أن يكون أكبر من 20 ديناراً');
                }
                if ($amount > $monthlyFee) {
                    throw new InvalidArgumentException("مبلغ التخفيض الإنساني ({$amount} د) لا يمكن أن يتجاوز المعلوم الشهري ({$monthlyFee} د)");
                }
                $finalAmount = round($amount, 2);
            } else {
                // full_waiver
                $finalAmount = null;
            }

            $userId = $request->user()?->id;

            // إذا كان لدى التلميذ تخفيض سنوي قديم (EnrollmentDiscount)،
            // نقوم بإلغائه موثقاً وتحويل الأشهر غير المستهدفة إلى تخفيضات شهرية لضمان عدم ضياعها!
            $activeAnnual = EnrollmentDiscount::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('academic_year_id', $academicYear->id)
                ->whereNull('cancelled_at')
                ->first();

            if ($activeAnnual) {
                $activeAnnual->update([
                    'cancelled_at'        => now(),
                    'cancelled_by'        => $userId,
                    'cancellation_reason' => 'تحويل التخفيض السنوي إلى تخفيضات شهرية مع تعديل فترة ' . $startMonth . ' إلى ' . $endMonth,
                ]);

                // ننشئ تخفيضاً شهرياً للأشهر السابقة واللاحقة بنفس القيمة السنوية
                $allMonths = $this->academicYearMonths($academicYear);
                $annualAmt = (float) $activeAnnual->amount;

                $beforeMonths = array_filter($allMonths, fn ($m) => $m['key'] < $startMonth);
                if (! empty($beforeMonths)) {
                    $firstBefore = reset($beforeMonths)['key'];
                    $lastBefore  = end($beforeMonths)['key'];
                    MonthlyDiscount::create([
                        'enrollment_id'    => $enrollment->id,
                        'academic_year_id' => $academicYear->id,
                        'discount_type'    => MonthlyDiscount::TYPE_NORMAL_MONTHLY,
                        'monthly_amount'   => $annualAmt,
                        'fee_category'     => 'tuition',
                        'start_month'      => $firstBefore,
                        'end_month'        => $lastBefore,
                        'reason'           => $activeAnnual->reason,
                        'notes'            => 'مستمر من التخفيض السنوي قبل تعديل ' . $startMonth,
                        'created_by'       => $userId,
                    ]);
                }

                $afterMonths = array_filter($allMonths, fn ($m) => $m['key'] > $endMonth);
                if (! empty($afterMonths)) {
                    $firstAfter = reset($afterMonths)['key'];
                    $lastAfter  = end($afterMonths)['key'];
                    MonthlyDiscount::create([
                        'enrollment_id'    => $enrollment->id,
                        'academic_year_id' => $academicYear->id,
                        'discount_type'    => MonthlyDiscount::TYPE_NORMAL_MONTHLY,
                        'monthly_amount'   => $annualAmt,
                        'fee_category'     => 'tuition',
                        'start_month'      => $firstAfter,
                        'end_month'        => $lastAfter,
                        'reason'           => $activeAnnual->reason,
                        'notes'            => 'مستمر من التخفيض السنوي بعد تعديل ' . $endMonth,
                        'created_by'       => $userId,
                    ]);
                }
            }

            // التعامل الذكي مع التخفيضات الشهرية المتداخلة (Split & Preserve)
            $overlappingDiscounts = MonthlyDiscount::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('academic_year_id', $academicYear->id)
                ->where('fee_category', 'tuition')
                ->active()
                ->where(function ($q) use ($startMonth, $endMonth) {
                    $q->where('start_month', '<=', $endMonth)
                      ->where('end_month', '>=', $startMonth);
                })
                ->get();

            foreach ($overlappingDiscounts as $old) {
                // إلغاء التخفيض القديم موثّقاً
                $old->update([
                    'cancelled_at'        => now(),
                    'cancelled_by'        => $userId,
                    'cancellation_reason' => 'تعديل التخفيض للفترة ' . $startMonth . ' إلى ' . $endMonth . ' (' . $data['reason'] . ')',
                ]);

                // هل يوجد جزء سابق لـ startMonth يجب الحفاظ عليه؟
                if ($old->start_month < $startMonth) {
                    $beforeEnd = Carbon::parse($startMonth . '-01')->subMonth()->format('Y-m');
                    if ($old->start_month <= $beforeEnd) {
                        MonthlyDiscount::create([
                            'enrollment_id'    => $enrollment->id,
                            'academic_year_id' => $academicYear->id,
                            'discount_type'    => $old->discount_type,
                            'monthly_amount'   => $old->monthly_amount,
                            'fee_category'     => 'tuition',
                            'start_month'      => $old->start_month,
                            'end_month'        => $beforeEnd,
                            'reason'           => $old->reason,
                            'notes'            => ($old->notes ? $old->notes . ' | ' : '') . 'محفوظ تلقائياً قبل تعديل ' . $startMonth,
                            'created_by'       => $userId,
                        ]);
                    }
                }

                // هل يوجد جزء لاحق لـ endMonth يجب الحفاظ عليه؟
                if ($old->end_month > $endMonth) {
                    $afterStart = Carbon::parse($endMonth . '-01')->addMonth()->format('Y-m');
                    if ($afterStart <= $old->end_month) {
                        MonthlyDiscount::create([
                            'enrollment_id'    => $enrollment->id,
                            'academic_year_id' => $academicYear->id,
                            'discount_type'    => $old->discount_type,
                            'monthly_amount'   => $old->monthly_amount,
                            'fee_category'     => 'tuition',
                            'start_month'      => $afterStart,
                            'end_month'        => $old->end_month,
                            'reason'           => $old->reason,
                            'notes'            => ($old->notes ? $old->notes . ' | ' : '') . 'محفوظ تلقائياً بعد تعديل ' . $endMonth,
                            'created_by'       => $userId,
                        ]);
                    }
                }
            }

            // إنشاء التخفيض الجديد المطلوب
            $newDiscount = MonthlyDiscount::create([
                'enrollment_id'    => $enrollment->id,
                'academic_year_id' => $academicYear->id,
                'discount_type'    => $data['discount_type'],
                'monthly_amount'   => $finalAmount,
                'fee_category'     => 'tuition',
                'start_month'      => $startMonth,
                'end_month'        => $endMonth,
                'reason'           => trim($data['reason']),
                'notes'            => $data['notes'] ?? null,
                'created_by'       => $userId,
            ]);

            return response()->json([
                'message'  => 'تم حفظ التخفيض بنجاح للفترة المحددة دون المساس ببقية الأشهر',
                'discount' => [
                    'id'             => $newDiscount->id,
                    'start_month'    => $newDiscount->start_month,
                    'end_month'      => $newDiscount->end_month,
                    'discount_type'  => $newDiscount->discount_type,
                    'monthly_amount' => $newDiscount->monthly_amount,
                ],
            ]);
        });
    }

    /**
     * حذف/إلغاء تخفيض لشهر محدد أو لكامل الفترة مع التوثيق الكامل.
     */
    public function remove(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enrollment_id' => ['required', 'integer', 'exists:enrollments,id'],
            'target_month'  => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'discount_id'   => ['nullable', 'integer'],
            'reason'        => ['required', 'string', 'max:500'],
        ], [
            'enrollment_id.required' => 'التسجيل إجباري',
            'reason.required'        => 'سبب الحذف/الإلغاء إجباري',
            'target_month.regex'     => 'صيغة الشهر غير صحيحة',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $enrollment = Enrollment::whereKey($data['enrollment_id'])->lockForUpdate()->firstOrFail();
            $academicYear = AcademicYear::findOrFail($enrollment->academic_year_id);
            $userId = $request->user()?->id;
            $reason = trim($data['reason']);

            // إذا كان المطلوب حذف تخفيض شهر محدد
            if (! empty($data['target_month'])) {
                $targetMonth = $data['target_month'];

                // 1. فحص التخفيض السنوي (EnrollmentDiscount)
                $annual = EnrollmentDiscount::query()
                    ->where('enrollment_id', $enrollment->id)
                    ->where('academic_year_id', $academicYear->id)
                    ->whereNull('cancelled_at')
                    ->first();

                if ($annual) {
                    $annual->update([
                        'cancelled_at'        => now(),
                        'cancelled_by'        => $userId,
                        'cancellation_reason' => $reason . ' (إلغاء لشهر ' . $targetMonth . ')',
                    ]);

                    // ننشئ تخفيضات شهرية لجميع الأشهر الأخرى عدا هذا الشهر
                    $allMonths = $this->academicYearMonths($academicYear);
                    $annualAmt = (float) $annual->amount;

                    $beforeMonths = array_filter($allMonths, fn ($m) => $m['key'] < $targetMonth);
                    if (! empty($beforeMonths)) {
                        MonthlyDiscount::create([
                            'enrollment_id'    => $enrollment->id,
                            'academic_year_id' => $academicYear->id,
                            'discount_type'    => MonthlyDiscount::TYPE_NORMAL_MONTHLY,
                            'monthly_amount'   => $annualAmt,
                            'fee_category'     => 'tuition',
                            'start_month'      => reset($beforeMonths)['key'],
                            'end_month'        => end($beforeMonths)['key'],
                            'reason'           => $annual->reason,
                            'notes'            => 'مستمر من التخفيض السنوي قبل استثناء ' . $targetMonth,
                            'created_by'       => $userId,
                        ]);
                    }

                    $afterMonths = array_filter($allMonths, fn ($m) => $m['key'] > $targetMonth);
                    if (! empty($afterMonths)) {
                        MonthlyDiscount::create([
                            'enrollment_id'    => $enrollment->id,
                            'academic_year_id' => $academicYear->id,
                            'discount_type'    => MonthlyDiscount::TYPE_NORMAL_MONTHLY,
                            'monthly_amount'   => $annualAmt,
                            'fee_category'     => 'tuition',
                            'start_month'      => reset($afterMonths)['key'],
                            'end_month'        => end($afterMonths)['key'],
                            'reason'           => $annual->reason,
                            'notes'            => 'مستمر من التخفيض السنوي بعد استثناء ' . $targetMonth,
                            'created_by'       => $userId,
                        ]);
                    }
                }

                // 2. فحص التخفيض الشهري (MonthlyDiscount) المغطي للشهر
                $activeMonthly = MonthlyDiscount::query()
                    ->where('enrollment_id', $enrollment->id)
                    ->where('academic_year_id', $academicYear->id)
                    ->where('fee_category', 'tuition')
                    ->active()
                    ->where('start_month', '<=', $targetMonth)
                    ->where('end_month', '>=', $targetMonth)
                    ->first();

                if ($activeMonthly) {
                    $activeMonthly->update([
                        'cancelled_at'        => now(),
                        'cancelled_by'        => $userId,
                        'cancellation_reason' => $reason . ' (إلغاء لشهر ' . $targetMonth . ')',
                    ]);

                    // الحفاظ على الجزء السابق
                    if ($activeMonthly->start_month < $targetMonth) {
                        $beforeEnd = Carbon::parse($targetMonth . '-01')->subMonth()->format('Y-m');
                        if ($activeMonthly->start_month <= $beforeEnd) {
                            MonthlyDiscount::create([
                                'enrollment_id'    => $enrollment->id,
                                'academic_year_id' => $academicYear->id,
                                'discount_type'    => $activeMonthly->discount_type,
                                'monthly_amount'   => $activeMonthly->monthly_amount,
                                'fee_category'     => 'tuition',
                                'start_month'      => $activeMonthly->start_month,
                                'end_month'        => $beforeEnd,
                                'reason'           => $activeMonthly->reason,
                                'notes'            => ($activeMonthly->notes ? $activeMonthly->notes . ' | ' : '') . 'محفوظ قبل استثناء شهر ' . $targetMonth,
                                'created_by'       => $userId,
                            ]);
                        }
                    }

                    // الحفاظ على الجزء اللاحق
                    if ($activeMonthly->end_month > $targetMonth) {
                        $afterStart = Carbon::parse($targetMonth . '-01')->addMonth()->format('Y-m');
                        if ($afterStart <= $activeMonthly->end_month) {
                            MonthlyDiscount::create([
                                'enrollment_id'    => $enrollment->id,
                                'academic_year_id' => $academicYear->id,
                                'discount_type'    => $activeMonthly->discount_type,
                                'monthly_amount'   => $activeMonthly->monthly_amount,
                                'fee_category'     => 'tuition',
                                'start_month'      => $afterStart,
                                'end_month'        => $activeMonthly->end_month,
                                'reason'           => $activeMonthly->reason,
                                'notes'            => ($activeMonthly->notes ? $activeMonthly->notes . ' | ' : '') . 'محفوظ بعد استثناء شهر ' . $targetMonth,
                                'created_by'       => $userId,
                            ]);
                        }
                    }
                }

                return response()->json([
                    'message' => 'تم حذف التخفيض لشهر ' . $targetMonth . ' بنجاح مع الحفاظ على بقية الأشهر',
                ]);
            }

            // إذا كان المطلوب إلغاء التخفيض بالمعرّف المباشر (discount_id)
            if (! empty($data['discount_id'])) {
                $mDiscount = MonthlyDiscount::find($data['discount_id']);
                if ($mDiscount && ! $mDiscount->isCancelled()) {
                    $mDiscount->update([
                        'cancelled_at'        => now(),
                        'cancelled_by'        => $userId,
                        'cancellation_reason' => $reason,
                    ]);

                    return response()->json([
                        'message' => 'تم إلغاء التخفيض الشهري بنجاح',
                    ]);
                }

                $aDiscount = EnrollmentDiscount::find($data['discount_id']);
                if ($aDiscount && ! $aDiscount->isCancelled()) {
                    $aDiscount->update([
                        'cancelled_at'        => now(),
                        'cancelled_by'        => $userId,
                        'cancellation_reason' => $reason,
                    ]);

                    return response()->json([
                        'message' => 'تم إلغاء التخفيض السنوي بنجاح',
                    ]);
                }
            }

            throw new InvalidArgumentException('لم يتم العثور على تخفيض نشط لإلغائه');
        });
    }

    /**
     * قائمة الأشهر العشرة للعام الدراسي.
     */
    private function academicYearMonths(AcademicYear $year): array
    {
        $startYear = (int) Carbon::parse($year->start_date)->format('Y');
        $months    = [];

        foreach (self::SCHOOL_MONTHS as $month) {
            $calendarYear = $month >= 9 ? $startYear : $startYear + 1;
            $key          = sprintf('%04d-%02d', $calendarYear, $month);

            $months[] = [
                'key'       => $key,
                'label'     => self::MONTH_NAMES_AR[sprintf('%02d', $month)] ?? $key,
                'year'      => $calendarYear,
                'month_num' => $month,
            ];
        }

        return $months;
    }

    /**
     * ترتيب أبجدي عربي موحّد.
     */
    private function normalizeName(string $name): string
    {
        $name = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', trim($name)) ?? $name;
        $name = str_replace(['أ', 'إ', 'آ', 'ى', 'ة'], ['ا', 'ا', 'ا', 'ي', 'ه'], $name);

        return mb_strtolower(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }
}
