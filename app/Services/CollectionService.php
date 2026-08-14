<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\MonthlyDiscount;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\StudentFee;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CollectionService
{
    private const SCHOOL_MONTHS = [9, 10, 11, 12, 1, 2, 3, 4, 5, 6];

    private const MONTH_NAMES_AR = [
        '01' => 'جانفي', '02' => 'فيفري', '03' => 'مارس',
        '04' => 'أفريل', '05' => 'ماي', '06' => 'جوان',
        '07' => 'جويلية', '08' => 'أوت', '09' => 'سبتمبر',
        '10' => 'أكتوبر', '11' => 'نوفمبر', '12' => 'ديسمبر',
    ];

    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly LedgerService $ledgerService,
    ) {}

    public function collect(array $data, int $createdBy): array
    {
        $key = $data['idempotency_key'] ?? null;

        // إعادة الإرسال: إن وُجد إيصال مخزّن بنفس المفتاح نُعيده كما هو بلا تكرار.
        if ($key) {
            $existing = Payment::where('idempotency_key', $key)->first();
            if ($existing && is_array($existing->meta)) {
                return $existing->meta;
            }
        }

        try {
            return DB::transaction(function () use ($data, $createdBy, $key) {
                // قفل صف التسجيل يُسلسِل كل عمليات الاستخلاص لنفس التسجيل،
                // فيمنع دفع نفس الشهر مرتين عند الطلبات المتزامنة.
                $enrollment = Enrollment::whereKey($data['enrollment_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $enrollment->load([
                    'student.guardians',
                    'academicYear',
                    'level',
                    'section',
                ]);

                // حارس سلامة مالية: التسجيل يجب أن يخصّ التلميذ المحدَّد نفسه.
                if ((int) $enrollment->student_id !== (int) $data['student_id']) {
                    throw new \InvalidArgumentException(
                        'التسجيل المحدَّد لا يخصّ هذا التلميذ'
                    );
                }

                $months = $data['months'];
                sort($months);
                $this->validateMonths($months, $enrollment);
                // إعادة حساب المعاينة والتخفيضات على مستوى الخادم لحماية الاستخلاص
                $tuitionFeeTypeId = $data['items'][0]['fee_type_id'] ?? null;
                $preview = $this->preview($enrollment->id, $months, $tuitionFeeTypeId);

                if ($preview['is_fully_waived']) {
                    throw new \InvalidArgumentException('هذا المعلوم معفى كلياً ولا يوجد مبلغ مستحق.');
                }

                $maxPayable = (float) $preview['remaining_amount'];
                $tuitionItem = collect($data['items'])->firstWhere('fee_type_id', $tuitionFeeTypeId);
                $tuitionAmount = $tuitionItem ? round((float) $tuitionItem['amount'], 2) : 0.0;

                if ($tuitionAmount > $maxPayable + 0.001) {
                    throw new \InvalidArgumentException(
                        'المبلغ المدفوع ('.number_format($tuitionAmount, 2, '.', '').') يتجاوز المبلغ المتبقي المستحق ('.number_format($maxPayable, 2, '.', '').')'
                    );
                }

                $itemsTotal = round((float) array_sum(array_column($data['items'], 'amount')), 2);

                $monthsLabel = implode(' / ', array_map(
                    fn ($m) => (self::MONTH_NAMES_AR[substr($m, 5)] ?? $m).' '.substr($m, 0, 4),
                    $months
                ));

                // التوزيع الصريح على ديون السنوات السابقة (إن أرسله القابض) —
                // يُتحقَّق منه ويُقفَل قبل إنشاء الدفعة، فيبقي المتبقّي سليماً.
                $priorPlanned = $this->processPriorAllocations($data, $enrollment);
                $priorTotal = round((float) array_sum($priorPlanned), 2);

                $total = round($itemsTotal + $priorTotal, 2);

                $payment = Payment::create([

                    'student_id' => $data['student_id'],
                    'enrollment_id' => $data['enrollment_id'],
                    'months' => $months,
                    'amount' => $total,
                    'payment_date' => $data['payment_date'],
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'idempotency_key' => $key,
                    'created_by' => $createdBy,
                ]);

                $receiptItems = [];
                $feeIds = [];

                // أولاً: توزيعات متخلّدات السنوات السابقة — مصدرها الرسم القديم
                // فيصنّفها الدفتر كقبض دَين سابق لا كمدخول جديد.
                $allocationsBreakdown = [];

                foreach ($priorPlanned as $feeId => $amount) {
                    $oldFee = StudentFee::with('feeType:id,name_ar')->find($feeId);

                    PaymentAllocation::create([
                        'payment_id' => $payment->id,
                        'student_fee_id' => $feeId,
                        'amount_allocated' => $amount,
                    ]);

                    $this->paymentService->recalculateStudentFeeStatus($feeId);
                    $feeIds[] = $feeId;

                    $label = $oldFee?->description
                        ?? $oldFee?->feeType?->name_ar
                        ?? ('متخلّد قديم #'.$feeId);

                    $receiptItems[] = [
                        'fee_type_id' => $oldFee?->fee_type_id,
                        'fee_type_name' => $label,
                        'description' => $label,
                        'amount' => (float) $amount,
                        'is_prior_year' => true,
                    ];

                    $allocationsBreakdown[] = [
                        'type' => 'prior_year',
                        'student_fee_id' => (int) $feeId,
                        'description' => $label,
                        'amount' => (float) $amount,
                    ];
                }

                foreach ($data['items'] as $item) {
                    $feeType = FeeType::findOrFail($item['fee_type_id']);
                    $amount = round((float) $item['amount'], 2);

                    $studentFee = StudentFee::create([
                        'enrollment_id' => $enrollment->id,
                        'fee_plan_id' => null,
                        // الرابط البنيوي بنوع الرسم: عليه يعتمد الدفتر في تصنيف بند المداخيل
                        // بدل استخراج النوع من نصّ الوصف.
                        'fee_type_id' => $feeType->id,
                        'description' => $feeType->name_ar.' — '.$monthsLabel,
                        'amount_due' => $amount,
                        'due_date' => $data['payment_date'],
                        'status' => 'pending',
                    ]);

                    PaymentAllocation::create([
                        'payment_id' => $payment->id,
                        'student_fee_id' => $studentFee->id,
                        'amount_allocated' => $amount,
                    ]);

                    $feeIds[] = $studentFee->id;

                    $receiptItems[] = [
                        'fee_type_id' => $feeType->id,
                        'fee_type_name' => $feeType->name_ar,
                        'amount' => (float) $item['amount'],
                        'is_prior_year' => false,
                    ];

                    $allocationsBreakdown[] = [
                        'type' => 'current_year',
                        'student_fee_id' => (int) $studentFee->id,
                        'description' => $feeType->name_ar.' — '.$monthsLabel,
                        'amount' => (float) $item['amount'],
                    ];
                }

                // مصدر حقيقة واحد لحالة الرسم: تُحسب من التوزيعات لا تُكتب يدوياً.
                foreach ($feeIds as $feeId) {
                    $this->paymentService->recalculateStudentFeeStatus($feeId);
                }

                // إسقاط الدفعة في الدفتر النقدي المركزي داخل نفس المعاملة:
                // إما تُسجَّل الدفعة وأثرها النقدي معاً، أو لا شيء منهما.
                // بدون هذا السطر كان الاستخلاص لا يظهر في الخزينة ولا في التقارير.
                $this->ledgerService->recordPayment($payment);

                $guardian = $enrollment->student->guardians
                    ->sortByDesc(fn ($g) => $g->pivot->is_primary_contact ?? 0)
                    ->first();

                $actor = auth()->user();

                $receipt = [
                    'payment_id' => $payment->id,
                    'payment_date' => $data['payment_date'],
                    'created_at' => $payment->created_at->toIso8601String(),
                    'method' => $data['method'],
                    'reference' => $payment->reference,
                    'notes' => $payment->notes,
                    'months' => $months,
                    'months_label' => $monthsLabel,
                    'items_total' => $itemsTotal,
                    // توزيع الدفعة كما نفّذه النظام: المحاسب يرى التفصيل قبل/بعد التثبيت.
                    'prior_total' => $priorTotal,
                    'allocations' => $allocationsBreakdown,
                    // التخفيض لم يعد يُطبَّق عند القبض؛ يبقى الحقل صفراً للتوافق مع
                    // الوصولات القديمة وعرض الوصل. التخفيض السنوي يُعرض من مصدره.
                    'discount' => 0.0,
                    'total' => $total,
                    'items' => $receiptItems,
                    'student' => [
                        'id' => $enrollment->student->id,
                        'first_name' => $enrollment->student->first_name,
                        'last_name' => $enrollment->student->last_name,
                        'student_code' => $enrollment->student->student_code,
                    ],
                    'enrollment' => [
                        'id' => $enrollment->id,
                        'level' => $enrollment->level?->name,
                        'section' => $enrollment->section?->name,
                        'academic_year' => $enrollment->academicYear?->name,
                    ],
                    'guardian' => $guardian ? [
                        'first_name' => $guardian->first_name,
                        'last_name' => $guardian->last_name,
                        'phone' => $guardian->phone,
                    ] : null,
                    'created_by' => [
                        'id' => $createdBy,
                        'code' => $actor?->code ?? $actor?->username ?? (string) $createdBy,
                        'name' => trim(($actor?->first_name ?? '').' '.($actor?->last_name ?? '')),
                    ],
                ];

                // لقطة إيصال ثابتة تُعاد حرفياً عند إعادة إرسال نفس الطلب.
                $payment->update(['meta' => $receipt]);

                return $receipt;
            });
        } catch (QueryException $e) {
            if ($key && $this->isDuplicateKey($e)) {
                $existing = Payment::where('idempotency_key', $key)->firstOrFail();
                if (is_array($existing->meta)) {
                    return $existing->meta;
                }
            }
            throw $e;
        }
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        $code = (string) $e->getCode();

        return in_array($code, ['23000', '23505'], true)
            || str_contains($e->getMessage(), 'idempotency_key')
            || str_contains(strtolower($e->getMessage()), 'unique');
    }

    /**
     * تحقق من التوزيع الصريح على متخلّدات السنوات السابقة قبل إنشاء الدفعة.
     *
     * قواعد صارمة:
     *  - الرسم يجب أن يخصّ التلميذ نفسه.
     *  - الرسم يجب أن يكون من سنة دراسية سابقة (مصدر الدَّين القديم).
     *  - لا يمكن توزيع أكثر من متبقّي الرسم (مع القفل ضد التزامن).
     *
     * @return array<int,float> fee_id => مبلغ موزَّع
     */
    private function processPriorAllocations(array $data, Enrollment $enrollment): array
    {
        $input = $data['prior_allocations'] ?? [];
        $planned = [];

        foreach ($input as $allocation) {
            $feeId = (int) ($allocation['student_fee_id'] ?? 0);
            $amount = round((float) ($allocation['amount'] ?? 0), 2);

            if ($amount <= 0) {
                continue;
            }

            /** @var StudentFee|null $fee */
            $fee = StudentFee::query()->whereKey($feeId)->lockForUpdate()->first();

            if (! $fee || (int) $fee->enrollment?->student_id !== (int) $enrollment->student_id) {
                throw new \InvalidArgumentException('الرسم رقم '.$feeId.' غير موجود لهذا التلميذ');
            }

            if ((int) $fee->enrollment?->academic_year_id === (int) $enrollment->academic_year_id) {
                throw new \InvalidArgumentException(
                    'الرسم «'.$fee->description.'» من السنة الحالية؛ قائمة المعاليم تعالجه لا متخلّدات السنوات السابقة'
                );
            }

            $outstanding = $fee->outstanding();

            if ($amount > $outstanding) {
                throw new \InvalidArgumentException(
                    'مبلغ التوزيع ('.number_format($amount, 2, '.', '')
                    .') يتجاوز المتبقّي ('.number_format($outstanding, 2, '.', '')
                    .') للرسم: '.$fee->description
                );
            }

            $planned[$feeId] = round(($planned[$feeId] ?? 0.0) + $amount, 2);
        }

        return $planned;
    }

    public function monthLedger(int $enrollmentId): array
    {
        $payments = Payment::where('enrollment_id', $enrollmentId)
            ->whereNull('cancelled_at')
            ->whereNotNull('months')
            ->with(['paymentAllocations.studentFee', 'createdBy:id,first_name,last_name'])
            ->orderBy('payment_date')
            ->get();

        $ledger = [];
        foreach ($payments as $payment) {
            foreach ($payment->months ?? [] as $month) {
                $ledger[$month][] = [
                    'payment_id' => $payment->id,
                    'payment_date' => $payment->payment_date->format('Y-m-d'),
                    'method' => $payment->method,
                    'amount' => $payment->amount,
                    'created_by' => $payment->createdBy
                        ? $payment->createdBy->first_name.' '.$payment->createdBy->last_name
                        : null,
                    'items' => $payment->paymentAllocations->map(fn ($a) => [
                        'description' => $a->studentFee?->description,
                        'amount' => $a->amount_allocated,
                    ])->values(),
                ];
            }
        }
        ksort($ledger);

        return $ledger;
    }

    public function getAcademicYearMonths(AcademicYear $year): array
    {
        $startYear = (int) $year->start_date->format('Y');
        $months = [];
        foreach (self::SCHOOL_MONTHS as $m) {
            $y = $m >= 9 ? $startYear : $startYear + 1;
            $months[] = sprintf('%04d-%02d', $y, $m);
        }

        return $months;
    }

    public function getPaidMonths(int $enrollmentId): array
    {
        $paid = [];

        Payment::query()
            ->where('enrollment_id', $enrollmentId)
            ->whereNull('cancelled_at')
            ->whereNotNull('months')
            ->select('months')
            ->cursor()
            ->each(function ($payment) use (&$paid) {
                foreach ((array) $payment->months as $m) {
                    $paid[$m] = true;
                }
            });

        return array_keys($paid);
    }

    private function validateMonths(array $months, Enrollment $enrollment): void
    {
        $academicYear = $enrollment->academicYear;

        if (! $academicYear) {
            throw new \InvalidArgumentException('التسجيل غير مرتبط بسنة دراسية');
        }

        $allMonths = $this->getAcademicYearMonths($academicYear);
        $paidMonths = $this->getPaidMonths($enrollment->id);

        foreach ($months as $m) {
            if (! in_array($m, $allMonths, true)) {
                throw new \InvalidArgumentException(
                    'الشهر '.$m.' لا ينتمي إلى السنة الدراسية '.$academicYear->name
                );
            }
        }

        if (count(array_unique($months)) !== count($months)) {
            throw new \InvalidArgumentException('لا يمكن تكرار نفس الشهر في دفعة واحدة');
        }

        foreach ($months as $m) {
            if (in_array($m, $paidMonths, true)) {
                $label = self::MONTH_NAMES_AR[substr($m, 5)] ?? $m;
                throw new \InvalidArgumentException('شهر '.$label.' تم دفعه مسبقاً');
            }
        }

        $indices = array_map(fn ($m) => array_search($m, $allMonths, true), $months);
        sort($indices);
        for ($i = 1; $i < count($indices); $i++) {
            if ($indices[$i] !== $indices[$i - 1] + 1) {
                throw new \InvalidArgumentException('يجب أن تكون الأشهر المختارة متتالية بدون فجوات');
            }
        }

        $unpaidMonths = array_values(array_filter(
            $allMonths,
            function ($m) use ($paidMonths) {
                return ! in_array($m, $paidMonths, true);
            }
        ));

        if (empty($unpaidMonths)) {
            throw new \InvalidArgumentException('جميع أشهر السنة الدراسية مدفوعة');
        }

        if ($months[0] !== $unpaidMonths[0]) {
            $first = self::MONTH_NAMES_AR[substr($unpaidMonths[0], 5)] ?? $unpaidMonths[0];
            throw new \InvalidArgumentException('يجب البدء بدفع شهر '.$first.' قبل الشهر المختار');
        }
    }

    /**
     * معاينة الاستخلاص وحساب التخفيضات والصافي والمتبقي آلياً على مستوى الخادم.
     */
    public function preview(int $enrollmentId, array $months, ?int $feeTypeId = null): array
    {
        $enrollment = Enrollment::with(['student', 'academicYear', 'level'])->findOrFail($enrollmentId);
        $academicYear = $enrollment->academicYear;

        // 1. المعلوم الشهري المحدد في المخطط أو سعر نوع الرسم
        $grossPerMonth = (float) FeePlan::query()
            ->where('academic_year_id', $academicYear->id)
            ->where('level_id', $enrollment->level_id)
            ->where('frequency', 'monthly')
            ->sum('amount');

        if ($grossPerMonth <= 0 && $feeTypeId) {
            $feeType = FeeType::find($feeTypeId);
            $grossPerMonth = $feeType ? (float) $feeType->price : 0.0;
        }

        $items = [];
        $totalGross = 0.0;
        $totalDiscount = 0.0;
        $totalNetDue = 0.0;
        $totalPaid = 0.0;
        $totalRemaining = 0.0;
        $hasFullWaiver = false;
        $activeDiscountType = null;
        $activeDiscountReason = null;

        foreach ($months as $m) {
            $monthGross = $grossPerMonth;

            // 1. التخفيض الشهري المتكرر (monthly_discounts) — normal_monthly, full_waiver & humanitarian_fixed
            $monthlyDisc = MonthlyDiscount::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('academic_year_id', $academicYear->id)
                ->active()
                ->where('start_month', '<=', $m)
                ->where('end_month', '>=', $m)
                ->first();

            $discAmount = 0.0;
            $discType = null;
            $discReason = null;

            if ($monthlyDisc) {
                $discType = $monthlyDisc->discount_type;
                $discReason = $monthlyDisc->reason;
                if ($discType === MonthlyDiscount::TYPE_FULL_WAIVER) {
                    $discAmount = $monthGross;
                } elseif ($discType === MonthlyDiscount::TYPE_HUMANITARIAN_FIXED || $discType === MonthlyDiscount::TYPE_NORMAL_MONTHLY) {
                    $discAmount = (float) $monthlyDisc->monthly_amount;
                }
            } else {
                // 2. التخفيض السنوي العادي (enrollment_discounts)
                $annualDisc = $enrollment->activeDiscount($academicYear->id);
                if ($annualDisc && (float) $annualDisc->amount > 0) {
                    $discType = 'normal';
                    $discReason = $annualDisc->reason;
                    $discAmount = (float) $annualDisc->amount;
                }

            }

            $monthNetDue = max(0.0, round($monthGross - $discAmount, 2));

            // المبالغ المدفوعة مسبقاً لهذا الشهر
            $monthPaid = (float) PaymentAllocation::query()
                ->whereHas('payment', function ($q) use ($enrollment, $m) {
                    $q->where('enrollment_id', $enrollment->id)
                        ->whereNull('cancelled_at')
                        ->whereJsonContains('months', $m);
                })
                ->sum('amount_allocated');

            $monthRemaining = max(0.0, round($monthNetDue - $monthPaid, 2));

            if ($discType === MonthlyDiscount::TYPE_FULL_WAIVER) {
                $hasFullWaiver = true;
            }

            if ($discType && ! $activeDiscountType) {
                $activeDiscountType = $discType;
                $activeDiscountReason = $discReason;
            }

            $totalGross += $monthGross;
            $totalDiscount += $discAmount;
            $totalNetDue += $monthNetDue;
            $totalPaid += $monthPaid;
            $totalRemaining += $monthRemaining;

            $items[] = [
                'month' => $m,
                'gross_amount' => $monthGross,
                'discount_type' => $discType,
                'discount_amount' => $discAmount,
                'net_due' => $monthNetDue,
                'amount_paid' => $monthPaid,
                'remaining_amount' => $monthRemaining,
                'is_fully_waived' => $discType === MonthlyDiscount::TYPE_FULL_WAIVER,
                'discount_reason' => $discReason,
            ];
        }

        return [
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'months' => $months,
            'gross_amount' => round($totalGross, 2),
            'discount_type' => $activeDiscountType,
            'discount_amount' => round($totalDiscount, 2),
            'net_due' => round($totalNetDue, 2),
            'amount_paid' => round($totalPaid, 2),
            'remaining_amount' => round($totalRemaining, 2),
            'is_fully_waived' => $hasFullWaiver || round($totalRemaining, 2) <= 0.0,
            'discount_reason' => $activeDiscountReason,
            'can_collect' => ! $hasFullWaiver && round($totalRemaining, 2) > 0.0,
            'items' => $items,
        ];
    }
}
