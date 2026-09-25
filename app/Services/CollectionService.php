<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\ClubMonthlyDiscount;
use App\Models\ClubMonthlyFee;
use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\ManualStudentDebt;
use App\Models\MonthlyDiscount;
use App\Models\OpeningBalance;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Exceptions\OverStandardAmountException;
use App\Models\Student;
use App\Models\StudentFee;
use App\Services\PreschoolShortCycleService;
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
        private readonly ClubService $clubService,
        private readonly MonthCollectionCoreService $monthCoreService,
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

        if (in_array($data['collection_mode'] ?? null, ['first_half', 'remaining'], true)) {
            throw new \InvalidArgumentException(
                'القبض الجزئي للشهر غير متاح حالياً.'
            );
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

                $months = $data['months'] ?? [];
                sort($months);
                $items = $data['items'] ?? [];
                $tuitionFeeTypeId = $items[0]['fee_type_id'] ?? null;

                $isPreschool = $this->isPreschool($enrollment);
                $preschoolMonthDetails = [];
                $resolvedMonthAmounts = [];

                // استرجاع خطة الرسوم الرسمية للمستوى
                $monthlyFeePlan = FeePlan::query()
                    ->where('academic_year_id', $enrollment->academic_year_id)
                    ->where('level_id', $enrollment->level_id)
                    ->where('frequency', 'monthly')
                    ->first();
                $fullRate = (float) ($monthlyFeePlan?->amount ?? 0.0);
                if ($fullRate <= 0.0 && $tuitionFeeTypeId) {
                    $ft = FeeType::find($tuitionFeeTypeId);
                    $fullRate = $ft ? (float) $ft->price : 0.0;
                }
                $suggestedHalfRate = PreschoolShortCycleService::calculateHalfRate($fullRate);

                $manualAmountsMap = (array) ($data['manual_amounts'] ?? []);
                $singleManualAmount = isset($data['manual_amount']) ? (float) $data['manual_amount'] : null;

                if (! empty($months)) {
                    $this->assertManualAmountEligibility($enrollment, $data, $months);
                    $this->monthCoreService->assertMonthsNotCollected($enrollment, $months);
                    $this->validateMonths($months, $enrollment);

                    // استخلاص وتحقق مبالغ ما قبل المدرسية لشهر سبتمبر وجوان
                    foreach ($months as $m) {
                        if ($this->isPreschoolShortCycleMonth($enrollment, $m)) {
                            $enteredAmount = null;
                            if (isset($manualAmountsMap[$m])) {
                                $enteredAmount = round((float) $manualAmountsMap[$m], 2);
                            } elseif ($singleManualAmount !== null && count($months) === 1) {
                                $enteredAmount = round($singleManualAmount, 2);
                            } elseif (count($months) === 1 && ! empty($items)) {
                                $tItem = collect($items)->firstWhere('fee_type_id', $tuitionFeeTypeId);
                                if ($tItem && (float) $tItem['amount'] > 0) {
                                    $enteredAmount = round((float) $tItem['amount'], 2);
                                }
                            }

                            if ($enteredAmount === null) {
                                $enteredAmount = $suggestedHalfRate;
                            }

                            if ($enteredAmount <= 0) {
                                throw new \InvalidArgumentException('يجب أن يكون مبلغ الاستخلاص أكبر من صفر.');
                            }

                            $isOverStandard = ($fullRate > 0 && $enteredAmount > $fullRate + 0.001);
                            if ($isOverStandard && empty($data['confirm_over_standard'])) {
                                throw new OverStandardAmountException(
                                    "المبلغ المدخل ({$enteredAmount} د.ت) يتجاوز المعلوم الشهري الكامل ({$fullRate} د.ت) — يتطلب تأكيداً صريحاً.",
                                    $enteredAmount,
                                    $fullRate,
                                    $suggestedHalfRate
                                );
                            }

                            $preschoolMonthDetails[$m] = [
                                'entered_amount' => $enteredAmount,
                                'suggested_amount' => $suggestedHalfRate,
                                'full_rate' => $fullRate,
                                'over_standard' => $isOverStandard,
                            ];
                            $resolvedMonthAmounts[$m] = $enteredAmount;
                        }
                    }

                    // إنشاء رسوم النوادي غير المدفوعة للتلميذ ضمن نفس عملية الاستخلاص.
                    // عدم إدراجها في items يعني أنها تبقى متخلدة، بينما club_items يقبضها اختيارياً.
                    $this->clubService->ensureFeesForEnrollment($enrollment, $months, $createdBy);
                    // إعادة حساب المعاينة والتخفيضات على مستوى الخادم لحماية الاستخلاص.
                    $preview = $this->preview($enrollment->id, $months, $tuitionFeeTypeId, 'full', $resolvedMonthAmounts);

                    // مزامنة بند التمدرس في items لمطابقة المبالغ المحسوبة لما قبل المدرسية والأشهر العادية
                    if (! empty($preschoolMonthDetails)) {
                        $tuitionSum = (float) $preview['remaining_amount'];
                        $foundTuition = false;
                        foreach ($items as &$it) {
                            if ($tuitionFeeTypeId && (int) $it['fee_type_id'] === (int) $tuitionFeeTypeId) {
                                $it['amount'] = $tuitionSum;
                                $foundTuition = true;
                                break;
                            }
                        }
                        unset($it);
                        if (! $foundTuition && $tuitionSum > 0) {
                            $defaultTuitionType = FamilyService::findTuitionFeeType()
                                ?? FeeType::find($tuitionFeeTypeId)
                                ?? FeeType::first();
                            if ($defaultTuitionType) {
                                array_unshift($items, [
                                    'fee_type_id' => $defaultTuitionType->id,
                                    'amount' => $tuitionSum,
                                ]);
                                $tuitionFeeTypeId = $defaultTuitionType->id;
                            }
                        }
                    }
                } else {
                    // سداد دين قديم فقط لا يحتاج إلى شهر حالي أو بند رسوم جديد.
                    $preview = ['is_fully_waived' => false, 'remaining_amount' => 0.0];
                }

                if ($preview['is_fully_waived'] && empty($data['club_items']) && empty($data['prior_allocations'])) {
                    throw new \InvalidArgumentException('هذا المعلوم معفى كلياً ولا يوجد مبلغ مستحق.');
                }

                $maxPayable = (float) $preview['remaining_amount'];
                $tuitionItem = collect($items)->firstWhere('fee_type_id', $tuitionFeeTypeId);
                $tuitionAmount = $tuitionItem ? round((float) $tuitionItem['amount'], 2) : 0.0;

                if ($tuitionAmount > $maxPayable + 0.001) {
                    throw new \InvalidArgumentException(
                        'المبلغ المدفوع ('.number_format($tuitionAmount, 2, '.', '').') يتجاوز المبلغ المتبقي المستحق ('.number_format($maxPayable, 2, '.', '').')'
                    );
                }

                $clubItems = $this->validateClubItems($data['club_items'] ?? [], $enrollment, $months);
                $clubTotal = round((float) array_sum(array_column($clubItems, 'amount')), 2);
                $itemsTotal = round((float) array_sum(array_column($items, 'amount')) + $clubTotal, 2);

                $monthsLabel = implode(' / ', array_map(
                    fn ($m) => (self::MONTH_NAMES_AR[substr($m, 5)] ?? $m).' '.substr($m, 0, 4),
                    $months
                ));

                // التوزيع الصريح على ديون السنوات السابقة (إن أرسله القابض) —
                // يُتحقَّق منه ويُقفَل قبل إنشاء الدفعة، فيبقي المتبقّي سليماً.
                $priorPlanned = $this->processPriorAllocations($data, $enrollment);
                $priorTotal = round((float) array_sum(array_column($priorPlanned, 'amount')), 2);

                $total = round($itemsTotal + $priorTotal, 2);

                // K1: لا وصل بصفر — أي عملية استخلاص يجب أن تحرّك مالاً فعلياً.
                if ($total <= 0) {
                    throw new \InvalidArgumentException(
                        'لا يمكن إنشاء وصل بمبلغ صفر؛ أدخل مبلغاً أكبر من صفر.'
                    );
                }

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

                foreach ($priorPlanned as $priorItem) {
                    $feeId = $priorItem['student_fee_id'];
                    $amount = $priorItem['amount'];
                    $oldFee = StudentFee::with('feeType:id,name_ar')->find($feeId);

                    PaymentAllocation::create([
                        'payment_id' => $payment->id,
                        'student_fee_id' => $feeId,
                        'manual_student_debt_id' => $priorItem['manual_student_debt_id'],
                        'opening_balance_id' => $priorItem['opening_balance_id'],
                        'amount_allocated' => $amount,
                    ]);

                    $this->paymentService->recalculateStudentFeeStatus($feeId);
                    $feeIds[] = $feeId;

                    if ($priorItem['manual_student_debt_id']) {
                        $debt = ManualStudentDebt::find($priorItem['manual_student_debt_id']);
                        if ($debt) {
                            $rem = $debt->outstanding();
                            $debt->update([
                                'status' => $rem <= 0 ? ManualStudentDebt::STATUS_PAID : ManualStudentDebt::STATUS_PARTIAL,
                            ]);
                        }
                    }

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
                        'allocation_type' => $priorItem['opening_balance_id'] ? 'opening_balance' : ($priorItem['manual_student_debt_id'] ? 'manual_student_debt' : 'student_fee'),
                        'source_id' => $priorItem['opening_balance_id'] ?: ($priorItem['manual_student_debt_id'] ?: (int) $feeId),
                        'description' => $label,
                        'amount' => (float) $amount,
                        'is_prior_debt' => true,
                        // الحقول القديمة لضمان التوافق العكسي (Backward Compatibility)
                        'type' => 'prior_year',
                        'student_fee_id' => (int) $feeId,
                        'manual_student_debt_id' => $priorItem['manual_student_debt_id'],
                        'opening_balance_id' => $priorItem['opening_balance_id'],
                    ];
                }

                foreach ($clubItems as $clubItem) {
                    $clubFee = ClubMonthlyFee::whereKey($clubItem['club_monthly_fee_id'])->lockForUpdate()->firstOrFail();
                    $clubFee->update([
                        'amount_paid' => number_format((float) $clubFee->amount_paid + $clubItem['amount'], 2, '.', ''),
                        'status' => ((float) $clubFee->amount_paid + $clubItem['amount']) >= (float) $clubFee->amount_due
                            ? ClubMonthlyFee::STATUS_PAID
                            : ClubMonthlyFee::STATUS_PARTIAL,
                        'paid_at' => $data['payment_date'],
                        'method' => $data['method'],
                        'reference' => $data['reference'] ?? null,
                        'notes' => $data['notes'] ?? null,
                        'created_by' => $createdBy,
                    ]);

                    $studentFee = $clubFee->studentFee()->firstOrFail();
                    PaymentAllocation::create([
                        'payment_id' => $payment->id,
                        'student_fee_id' => $studentFee->id,
                        'amount_allocated' => $clubItem['amount'],
                    ]);
                    $feeIds[] = $studentFee->id;
                    $this->paymentService->recalculateStudentFeeStatus($studentFee->id);

                    $label = $studentFee->description ?? 'معلوم النادي';
                    $receiptItems[] = [
                        'fee_type_id' => null,
                        'fee_type_name' => $label,
                        'description' => $label,
                        'amount' => (float) $clubItem['amount'],
                        'is_club_fee' => true,
                        'is_prior_year' => false,
                    ];
                    $allocationsBreakdown[] = [
                        'allocation_type' => 'club_fee',
                        'source_id' => (int) $clubFee->id,
                        'description' => $label,
                        'amount' => (float) $clubItem['amount'],
                        'is_prior_debt' => false,
                        'type' => 'club_fee',
                        'student_fee_id' => (int) $studentFee->id,
                        'club_monthly_fee_id' => (int) $clubFee->id,
                    ];
                }

                foreach ($items as $item) {
                    $feeType = FeeType::findOrFail($item['fee_type_id']);
                    $isTuition = ($tuitionFeeTypeId && (int) $feeType->id === (int) $tuitionFeeTypeId);

                    if ($isTuition && ! empty($preschoolMonthDetails)) {
                        // إنشاء رسم طالب منفصل لكل شهر من الأشهر المطلوبة بالمبلغ المخصص (يدوي لما قبل المدرسي، وكامل للأشهر العادية)
                        foreach ($months as $m) {
                            if ($this->isPreschoolShortCycleMonth($enrollment, $m)) {
                                $mAmount = (float) ($resolvedMonthAmounts[$m] ?? $preschoolMonthDetails[$m]['entered_amount'] ?? $suggestedHalfRate);
                            } else {
                                $pItem = collect($preview['items'] ?? [])->firstWhere('month', $m);
                                $mAmount = (float) ($pItem['remaining_amount'] ?? $pItem['net_due'] ?? $fullRate);
                            }
                            $mDueDate = $this->resolveMonthDueDate($enrollment, $m, $data['payment_date']);
                            $mLabel = (self::MONTH_NAMES_AR[substr($m, 5)] ?? $m).' '.substr($m, 0, 4);

                            $studentFee = StudentFee::create([
                                'enrollment_id' => $enrollment->id,
                                'fee_plan_id' => $monthlyFeePlan?->id,
                                'fee_type_id' => $feeType->id,
                                'description' => $feeType->name_ar.' — '.$mLabel,
                                'amount_due' => $mAmount,
                                'due_date' => $mDueDate,
                                'status' => 'pending',
                            ]);

                            PaymentAllocation::create([
                                'payment_id' => $payment->id,
                                'student_fee_id' => $studentFee->id,
                                'amount_allocated' => $mAmount,
                            ]);

                            $feeIds[] = $studentFee->id;

                            $receiptItems[] = [
                                'fee_type_id' => $feeType->id,
                                'fee_type_name' => $feeType->name_ar.' — '.$mLabel,
                                'description' => $feeType->name_ar.' — '.$mLabel,
                                'amount' => $mAmount,
                                'is_prior_year' => false,
                            ];

                            $allocationsBreakdown[] = [
                                'allocation_type' => 'student_fee',
                                'source_id' => (int) $studentFee->id,
                                'description' => $feeType->name_ar.' — '.$mLabel,
                                'amount' => $mAmount,
                                'is_prior_debt' => false,
                                'type' => 'current_year',
                                'student_fee_id' => (int) $studentFee->id,
                            ];
                        }
                    } else {
                        $amount = round((float) $item['amount'], 2);

                        $studentFee = StudentFee::create([
                            'enrollment_id' => $enrollment->id,
                            'fee_plan_id' => null,
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
                            'description' => $feeType->name_ar.' — '.$monthsLabel,
                            'amount' => (float) $item['amount'],
                            'is_prior_year' => false,
                        ];

                        $allocationsBreakdown[] = [
                            'allocation_type' => 'student_fee',
                            'source_id' => (int) $studentFee->id,
                            'description' => $feeType->name_ar.' — '.$monthsLabel,
                            'amount' => (float) $item['amount'],
                            'is_prior_debt' => false,
                            'type' => 'current_year',
                            'student_fee_id' => (int) $studentFee->id,
                        ];
                    }
                }

                // مصدر حقيقة واحد لحالة الرسم: تُحسب من التوزيعات لا تُكتب يدوياً.
                foreach ($feeIds as $feeId) {
                    $this->paymentService->recalculateStudentFeeStatus($feeId);
                }

                // إسقاط الدفعة في الدفتر النقدي المركزي داخل نفس المعاملة:
                // إما تُسجَّل الدفعة وأثرها النقدي معاً، أو لا شيء منهما.
                // بدون هذا السطر كان الاستخلاص لا يظهر في الخزينة ولا في التقارير.
                $this->ledgerService->recordPayment($payment);

                $guardianPayload = self::resolveGuardianPayload($enrollment->student);

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
                    'preschool_manual_amounts' => $preschoolMonthDetails,
                    'entered_amount' => ! empty($preschoolMonthDetails) ? reset($preschoolMonthDetails)['entered_amount'] : null,
                    'suggested_amount' => ! empty($preschoolMonthDetails) ? reset($preschoolMonthDetails)['suggested_amount'] : null,
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
                    'guardian' => $guardianPayload,
                    'created_by' => [
                        'id' => $createdBy,
                        'code' => $actor?->code ?? $actor?->username ?? (string) $createdBy,
                        'name' => trim(($actor?->first_name ?? '').' '.($actor?->last_name ?? '')),
                    ],
                ];

                if (! empty($preschoolMonthDetails)) {
                    $noteParts = [];
                    foreach ($preschoolMonthDetails as $pm => $det) {
                        $noteParts[] = "{$pm}: entered={$det['entered_amount']}, suggested={$det['suggested_amount']}"
                            . ($det['over_standard'] ? ' (over_standard confirmed)' : '');
                    }
                    $extraNote = 'Preschool manual: ' . implode('; ', $noteParts);
                    $payment->notes = $payment->notes ? ($payment->notes . ' | ' . $extraNote) : $extraNote;
                    $payment->save();
                    $receipt['notes'] = $payment->notes;
                }

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

    /**
     * بيانات وليّ الأمر لعرضها في الوصل.
     *
     * الأصل هو جدول الربط guardian_student (مع تفضيل جهة الاتصال الأساسية)،
     * لأنه يحمل بيانات الوليّ المُنشأة عند الترسيم. لكن التلاميذ الذين دخلوا
     * عبر الاستيراد ليست لهم صفوف في الربط، وبياناتهم محفوظة في أعمدة
     * students المسطَّحة؛ فبدون هذا التراجع كان الوصل يُطبع بلا اسم الوليّ
     * ولا هاتفه رغم توفّر البيانات.
     *
     * طبقة عرض بحتة: لا تكتب شيئاً ولا تمسّ أيّ مبلغ أو قيد نقدي، ولا تُغيّر
     * تجميع العائلات (FamilyService يبقى على مصدره كما هو).
     *
     * @return array{first_name: string|null, last_name: string|null, phone: string|null, email: string|null}|null
     */
    public static function resolveGuardianPayload(?Student $student): ?array
    {
        if (! $student) {
            return null;
        }

        $guardian = $student->relationLoaded('guardians')
            ? $student->guardians
                ->sortByDesc(fn ($g) => $g->pivot->is_primary_contact ?? 0)
                ->first()
            : $student->guardians()
                ->orderByDesc('guardian_student.is_primary_contact')
                ->first();

        if ($guardian) {
            return [
                'first_name' => $guardian->first_name,
                'last_name' => $guardian->last_name,
                'phone' => $guardian->phone,
                'email' => $guardian->email,
            ];
        }

        $first = trim((string) ($student->guardian_first_name ?? ''));
        $last = trim((string) ($student->guardian_last_name ?? ''));
        $phone = trim((string) ($student->guardian_phone ?? ''));
        $email = trim((string) ($student->guardian_email ?? ''));

        // لا اسم ولا هاتف ⇒ لا بيانات وليّ فعلاً: نُبقي null كما كان السلوك.
        if ($first === '' && $last === '' && $phone === '') {
            return null;
        }

        return [
            'first_name' => $first !== '' ? $first : null,
            'last_name' => $last !== '' ? $last : null,
            'phone' => $phone !== '' ? $phone : null,
            'email' => $email !== '' ? $email : null,
        ];
    }

    private function validateClubItems(array $items, Enrollment $enrollment, array $months): array
    {
        $validated = [];
        foreach ($items as $item) {
            $fee = ClubMonthlyFee::with('studentFee')
                ->whereKey((int) ($item['club_monthly_fee_id'] ?? 0))
                ->lockForUpdate()
                ->first();
            $amount = round((float) ($item['amount'] ?? 0), 2);
            if (! $fee || ! $fee->studentFee || (int) $fee->enrollment_id !== (int) $enrollment->id) {
                throw new \InvalidArgumentException('رسم النادي المحدد لا يخص هذا التلميذ أو الشهر المختار');
            }
            $remaining = round((float) $fee->amount_due - (float) $fee->amount_paid, 2);
            if ($amount <= 0 || $amount > $remaining) {
                throw new \InvalidArgumentException('مبلغ معلوم النادي يتجاوز المتبقي لهذا الشهر');
            }
            $validated[] = ['club_monthly_fee_id' => $fee->id, 'amount' => $amount];
        }

        return $validated;
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
     *  - الهدف واحد فقط: student_fee_id أو opening_balance_id أو manual_student_debt_id.
     *  - الرسم/الرصيد/الدَّين يجب أن يخصّ التلميذ نفسه.
     *  - الرسم يجب أن يكون من سنة دراسية سابقة (مصدر الدَّين القديم).
     *  - الدَّين اليدوي يجب أن يكون محمولاً إلى سنة تسجيل التلميذ الحالية وغير ملغى.
     *  - لا يمكن توزيع أكثر من متبقّي الرسم (مع القفل ضد التزامن).
     *
     * @return array<int,array<string,mixed>> مصفوفة التوزيعات المخططة
     */
    private function processPriorAllocations(array $data, Enrollment $enrollment): array
    {
        $input = $data['prior_allocations'] ?? [];
        $plannedItems = [];
        $plannedFeesTotal = [];
        $plannedManualDebts = [];
        $plannedOpeningBalances = [];

        foreach ($input as $allocation) {
            $feeId = (int) ($allocation['student_fee_id'] ?? 0);
            $openingBalanceId = (int) ($allocation['opening_balance_id'] ?? 0);
            $manualDebtId = (int) ($allocation['manual_student_debt_id'] ?? 0);
            $amount = round((float) ($allocation['amount'] ?? 0), 2);

            if ($amount <= 0) {
                continue;
            }

            if (array_sum([$feeId > 0, $openingBalanceId > 0, $manualDebtId > 0]) !== 1) {
                throw new \InvalidArgumentException('يجب تحديد student_fee_id أو opening_balance_id أو manual_student_debt_id فقط');
            }

            if ($openingBalanceId > 0) {
                $balance = OpeningBalance::query()
                    ->with('sourceStudentFee.enrollment')
                    ->whereKey($openingBalanceId)
                    ->lockForUpdate()
                    ->first();

                $sourceFee = $balance?->sourceStudentFee;
                if (! $balance || $balance->isCancelled() || (int) $balance->student_id !== (int) $enrollment->student_id || ! $sourceFee) {
                    throw new \InvalidArgumentException('الرصيد الافتتاحي رقم '.$openingBalanceId.' غير موجود لهذا التلميذ');
                }
                $feeId = (int) $sourceFee->id;

                $balanceOutstanding = $balance->outstanding();
                $alreadyPlannedBalance = (float) ($plannedOpeningBalances[$openingBalanceId] ?? 0.0);
                if ($alreadyPlannedBalance + $amount > $balanceOutstanding) {
                    throw new \InvalidArgumentException(
                        'مبلغ التوزيع ('.number_format($alreadyPlannedBalance + $amount, 2, '.', '')
                        .') يتجاوز المتبقّي ('.number_format($balanceOutstanding, 2, '.', '')
                        .') للرصيد الافتتاحي رقم '.$openingBalanceId
                    );
                }
                $plannedOpeningBalances[$openingBalanceId] = round($alreadyPlannedBalance + $amount, 2);
            }

            if ($manualDebtId > 0) {
                /** @var ManualStudentDebt|null $debt */
                $debt = ManualStudentDebt::query()
                    ->with('sourceStudentFee')
                    ->whereKey($manualDebtId)
                    ->lockForUpdate()
                    ->first();

                if (! $debt || $debt->isCancelled() || (int) $debt->student_id !== (int) $enrollment->student_id) {
                    throw new \InvalidArgumentException('الدَّين اليدوي رقم '.$manualDebtId.' غير موجود لهذا التلميذ');
                }

                if ((int) $debt->academic_year_id !== (int) $enrollment->academic_year_id) {
                    throw new \InvalidArgumentException('الدَّين اليدوي رقم '.$manualDebtId.' محمول إلى سنة دراسية أخرى');
                }

                $feeId = (int) ($debt->source_student_fee_id ?? 0);
                if (! $feeId) {
                    throw new \InvalidArgumentException('الدَّين اليدوي رقم '.$manualDebtId.' بلا رسم مصدر');
                }

                $debtOutstanding = $debt->outstanding();
                $alreadyPlannedDebt = (float) ($plannedManualDebts[$manualDebtId] ?? 0.0);
                if ($alreadyPlannedDebt + $amount > $debtOutstanding) {
                    throw new \InvalidArgumentException(
                        'مبلغ التوزيع ('.number_format($alreadyPlannedDebt + $amount, 2, '.', '')
                        .') يتجاوز المتبقّي ('.number_format($debtOutstanding, 2, '.', '')
                        .') للدَّين اليدوي: '.$debt->description
                    );
                }
                $plannedManualDebts[$manualDebtId] = round($alreadyPlannedDebt + $amount, 2);
            }

            /** @var StudentFee|null $fee */
            $fee = StudentFee::query()->whereKey($feeId)->lockForUpdate()->first();

            if (! $fee || (int) $fee->enrollment?->student_id !== (int) $enrollment->student_id) {
                throw new \InvalidArgumentException('الرسم رقم '.$feeId.' غير موجود لهذا التلميذ');
            }

            // جسر الدَّين اليدوي المُدخل جماعياً يقع تحت تسجيل السنة الحالية عمداً
            // (لا تسجيل سابق للمستوى الجديد). يُستثنى من فحص «السنة الحالية» فقط
            // عندما تتحقق العلاقة الفعلية كاملة: manual_student_debt_id يشير
            // فعلاً إلى دَين نشط لنفس التلميذ ومرتبط بهذا الرسم تحديداً.
            // التخصيصات بلا هدف صريح تبقى مرفوضة كما هي.
            $isManualDebtBridge = $manualDebtId > 0
                && (int) $fee->id === (int) $feeId
                && ManualStudentDebt::query()
                    ->whereKey($manualDebtId)
                    ->where('source_student_fee_id', $fee->id)
                    ->where('student_id', (int) $enrollment->student_id)
                    ->whereNull('cancelled_at')
                    ->exists();

            if (! $isManualDebtBridge && (int) $fee->enrollment?->academic_year_id === (int) $enrollment->academic_year_id) {
                throw new \InvalidArgumentException(
                    'الرسم «'.$fee->description.'» من السنة الحالية؛ قائمة المعاليم تعالجه لا متخلّدات السنوات السابقة'
                );
            }

            $outstanding = $fee->outstanding();

            $alreadyPlannedForFee = (float) ($plannedFeesTotal[$feeId] ?? 0.0);
            if ($alreadyPlannedForFee + $amount > $outstanding) {
                throw new \InvalidArgumentException(
                    'مبلغ التوزيع ('.number_format($alreadyPlannedForFee + $amount, 2, '.', '')
                    .') يتجاوز المتبقّي ('.number_format($outstanding, 2, '.', '')
                    .') للرسم: '.$fee->description
                );
            }

            $plannedFeesTotal[$feeId] = round($alreadyPlannedForFee + $amount, 2);

            $plannedItems[] = [
                'student_fee_id' => $feeId,
                'manual_student_debt_id' => $manualDebtId > 0 ? $manualDebtId : null,
                'opening_balance_id' => $openingBalanceId > 0 ? $openingBalanceId : null,
                'amount' => $amount,
            ];
        }

        return $plannedItems;
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

    public function getPartialMonths(int $enrollmentId): array
    {
        return [];
    }

    /**
     * التحقق مما إذا كان التلميذ مسجلاً في أقسام ما قبل المدرسية (الروضة، التمهيدي، التحضيري).
     */
    public function isPreschool(Enrollment $enrollment): bool
    {
        $levelCode = (string) ($enrollment->level?->code ?? $enrollment->section?->level?->code ?? '');

        return in_array($levelCode, PreschoolShortCycleService::ALLOWED_LEVELS, true);
    }

    /**
     * التحقق مما إذا كان الشهر المعني يمثل شهر بداية أو نهاية السنة (سبتمبر أو جوان) لقسم ما قبل مدرسي.
     */
    public function isPreschoolShortCycleMonth(Enrollment $enrollment, string $month): bool
    {
        if (! $this->isPreschool($enrollment)) {
            return false;
        }

        $academicYear = $enrollment->academicYear;
        $startMonth = $academicYear?->start_date?->format('Y-m');
        $endMonth = $academicYear?->end_date?->format('Y-m');

        return ($startMonth && $month === $startMonth)
            || ($endMonth && $month === $endMonth)
            || str_ends_with($month, '-09')
            || str_ends_with($month, '-06');
    }

    /**
     * التحقق من أهلية استخدام المبالغ اليدوية وضوابطها المحاسبية.
     */
    private function assertManualAmountEligibility(Enrollment $enrollment, array $data, array $months): void
    {
        $hasManual = isset($data['manual_amount']) || ! empty($data['manual_amounts']);
        $isPreschool = $this->isPreschool($enrollment);

        if ($hasManual && ! $isPreschool) {
            throw new \InvalidArgumentException('المبلغ اليدوي مخصص حصراً لأقسام الروضة والتمهيدي والتحضيري (PRE1, PRE2, PRE3).');
        }

        if (isset($data['manual_amount']) && (float) $data['manual_amount'] <= 0) {
            throw new \InvalidArgumentException('يجب أن يكون مبلغ الاستخلاص أكبر من صفر.');
        }

        if (! empty($data['manual_amounts'])) {
            foreach ($data['manual_amounts'] as $m => $amt) {
                if ((float) $amt <= 0) {
                    throw new \InvalidArgumentException('يجب أن يكون مبلغ الاستخلاص أكبر من صفر.');
                }
                if ($isPreschool && ! $this->isPreschoolShortCycleMonth($enrollment, (string) $m)) {
                    throw new \InvalidArgumentException("المبلغ اليدوي مخصص لشهري سبتمبر وجوان فقط لأقسام ما قبل المدرسية ({$m}).");
                }
            }
        }
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

        $isPreschool = $this->isPreschool($enrollment);
        $isPreschoolTerminalPair = $isPreschool && count($months) === 2 && (
            (str_ends_with($months[0], '-09') && str_ends_with($months[1], '-06')) ||
            (str_ends_with($months[0], '-06') && str_ends_with($months[1], '-09'))
        );
        $isPreschoolStandaloneShortMonth = $isPreschool && count($months) === 1 && (
            str_ends_with($months[0], '-09') || str_ends_with($months[0], '-06')
        );

        if (! $isPreschoolTerminalPair) {
            for ($i = 1; $i < count($indices); $i++) {
                if ($indices[$i] !== $indices[$i - 1] + 1) {
                    throw new \InvalidArgumentException('يجب أن تكون الأشهر المختارة متتالية بدون فجوات');
                }
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

        if (! $isPreschoolTerminalPair && ! $isPreschoolStandaloneShortMonth && $months[0] !== $unpaidMonths[0]) {
            $first = self::MONTH_NAMES_AR[substr($unpaidMonths[0], 5)] ?? $unpaidMonths[0];
            throw new \InvalidArgumentException('يجب البدء بدفع شهر '.$first.' قبل الشهر المختار');
        }
    }

    /**
     * معاينة الاستخلاص وحساب التخفيضات والصافي والمتبقي آلياً على مستوى الخادم.
     */
    public function preview(int $enrollmentId, array $months, ?int $feeTypeId = null, string $mode = 'full', array $manualAmounts = []): array
    {
        if (in_array($mode, ['first_half', 'remaining'], true)) {
            throw new \InvalidArgumentException(
                'القبض الجزئي للشهر غير متاح حالياً.'
            );
        }

        $enrollment = Enrollment::with(['student', 'academicYear', 'level'])->findOrFail($enrollmentId);
        $academicYear = $enrollment->academicYear;
        $this->clubService->ensureFeesForEnrollment($enrollment, $months);

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

        $feePlanMissing = ($grossPerMonth <= 0.0);

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
            $isPreschoolShortCycle = $this->isPreschoolShortCycleMonth($enrollment, $m);
            $monthGross = $grossPerMonth;
            $discAmount = 0.0;
            $discType = null;
            $discReason = null;

            if ($isPreschoolShortCycle) {
                $suggestedHalfRate = PreschoolShortCycleService::calculateHalfRate($grossPerMonth);
                $entered = isset($manualAmounts[$m]) ? (float) $manualAmounts[$m] : null;
                $monthGross = ($entered !== null && $entered > 0) ? $entered : $suggestedHalfRate;
                $monthNetDue = $monthGross;
            } else {
                // 1. التخفيض الشهري المتكرر (monthly_discounts) — normal_monthly, full_waiver & humanitarian_fixed
                $monthlyDisc = MonthlyDiscount::query()
                    ->where('enrollment_id', $enrollment->id)
                    ->where('academic_year_id', $academicYear->id)
                    ->active()
                    ->where('start_month', '<=', $m)
                    ->where('end_month', '>=', $m)
                    ->first();

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
            }

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
                'is_preschool_short_cycle' => $isPreschoolShortCycle,
                'suggested_amount' => $isPreschoolShortCycle ? PreschoolShortCycleService::calculateHalfRate($grossPerMonth) : null,
                'full_rate' => $isPreschoolShortCycle ? $grossPerMonth : null,
            ];
        }

        $clubItems = ClubMonthlyFee::query()
            ->with(['club:id,name', 'studentFee:id,club_monthly_fee_id', 'subscription'])
            ->where('enrollment_id', $enrollment->id)
            ->whereIn('month', $months)
            ->whereNull('cancelled_at')
            ->whereColumn('amount_paid', '<', 'amount_due')
            ->whereHas('club', function ($cq) use ($enrollment) {
                $cq->where('is_active', true)
                    ->where(function ($subQ) use ($enrollment) {
                        $subQ->whereHas('sections', fn ($s) => $s->where('sections.id', $enrollment->section_id))
                            ->orWhere(function ($noSec) use ($enrollment) {
                                $noSec->whereDoesntHave('sections')
                                    ->whereHas('subscriptions', fn ($sq) => $sq->where('student_id', $enrollment->student_id)
                                        ->where('academic_year_id', $enrollment->academic_year_id)
                                        ->where('status', 'active')
                                        ->whereNull('excluded_at'));
                            });
                    });
            })
            ->orderBy('month')
            ->get()
            ->filter(function (ClubMonthlyFee $fee) {
                $sub = $fee->subscription;
                if ($sub && $sub->start_date) {
                    $subStartMonth = substr($sub->start_date->toDateString(), 0, 7);
                    if ($fee->month < $subStartMonth && (float) $fee->amount_paid <= 0) {
                        return false;
                    }
                }
                if ($sub && ($sub->excluded_at !== null || $sub->status === 'cancelled') && (float) $fee->amount_paid <= 0) {
                    $subExcludedMonth = substr($sub->excluded_at->toDateString(), 0, 7);
                    if ($fee->month >= $subExcludedMonth) {
                        return false;
                    }
                }
                if ($sub) {
                    $clubWaiver = ClubMonthlyDiscount::query()
                        ->where('club_subscription_id', $sub->id)
                        ->active()
                        ->where('start_month', '<=', $fee->month)
                        ->where('end_month', '>=', $fee->month)
                        ->where('discount_type', ClubMonthlyDiscount::TYPE_FULL_WAIVER)
                        ->exists();
                    if ($clubWaiver) {
                        return false;
                    }
                }

                return true;
            })
            ->map(function (ClubMonthlyFee $fee) {
                $sub = $fee->subscription;
                $due = (float) $fee->amount_due;
                $paid = (float) $fee->amount_paid;
                if ($sub) {
                    $clubDisc = ClubMonthlyDiscount::query()
                        ->where('club_subscription_id', $sub->id)
                        ->active()
                        ->where('start_month', '<=', $fee->month)
                        ->where('end_month', '>=', $fee->month)
                        ->where('discount_type', ClubMonthlyDiscount::TYPE_HUMANITARIAN_FIXED)
                        ->first();
                    if ($clubDisc) {
                        $due = min($due, (float) $clubDisc->monthly_amount);
                    }
                }
                $remaining = max(0, round($due - $paid, 2));

                return [
                    'club_monthly_fee_id' => $fee->id,
                    'student_fee_id' => $fee->studentFee?->id,
                    'month' => $fee->month,
                    'club_name' => $fee->club?->name ?? 'النادي',
                    'amount_due' => $due,
                    'amount_paid' => $paid,
                    'remaining_amount' => $remaining,
                    'status' => $fee->status,
                ];
            })->values()->all();

        return [
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'collection_mode' => $mode,
            'months' => $months,
            'gross_amount' => round($totalGross, 2),
            'discount_type' => $activeDiscountType,
            'discount_amount' => round($totalDiscount, 2),
            'net_due' => round($totalNetDue, 2),
            'amount_paid' => round($totalPaid, 2),
            'remaining_amount' => round($totalRemaining, 2),
            'is_fully_waived' => $hasFullWaiver,
            'fee_plan_missing' => $feePlanMissing,
            'fee_plan_missing_message' => $feePlanMissing ? 'لا توجد خطة رسوم شهرية مضبوطة للسنة الدراسية والمستوى الحاليين.' : null,
            'discount_reason' => $activeDiscountReason,
            'can_collect' => ! $hasFullWaiver && ! $feePlanMissing && round($totalRemaining, 2) > 0.0,
            'items' => $items,
            'club_items' => $clubItems,
            'club_remaining_amount' => round((float) array_sum(array_column($clubItems, 'remaining_amount')), 2),
        ];
    }

    private function resolveMonthDueDate(Enrollment $enrollment, string $month, string $fallbackDate): string
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $matches)) {
            return "{$matches[1]}-{$matches[2]}-01";
        }

        return $fallbackDate;
    }
}

