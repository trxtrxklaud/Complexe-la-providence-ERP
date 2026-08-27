<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Club;
use App\Models\ClubMonthlyFee;
use App\Models\ClubSubscription;
use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Student;
use App\Models\StudentFee;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class FamilyService
{
    private const SCHOOL_MONTHS = [9, 10, 11, 12, 1, 2, 3, 4, 5, 6];
    private const CLUB_MONTHS = [9, 10, 11, 12, 1, 2, 3, 4, 5];

    private const MONTH_NAMES_AR = [
        '01' => 'جانفي', '02' => 'فيفري', '03' => 'مارس',
        '04' => 'أفريل', '05' => 'ماي', '06' => 'جوان',
        '07' => 'جويلية', '08' => 'أوت', '09' => 'سبتمبر',
        '10' => 'أكتوبر', '11' => 'نوفمبر', '12' => 'ديسمبر',
    ];

    public function __construct(
        protected CollectionService $collectionService,
        protected ClubService $clubService,
        protected PaymentService $paymentService,
        protected LedgerService $ledgerService
    ) {}

    /**
     * استخراج نوع الرسم الشهري الأساسي للمنصة بدقة ومطابقة تامة.
     */
    public static function findTuitionFeeType(): FeeType
    {
        $feeType = FeeType::where('is_active', true)
            ->where(function ($q) {
                $q->where('name_ar', 'like', '%تمدرس%')
                    ->orWhere('name_ar', 'like', '%شهر%')
                    ->orWhere('name_ar', 'like', '%التعليم الأساسي%')
                    ->orWhere('code', 'TUITION')
                    ->orWhere('code', 'like', '%month%')
                    ->orWhere('name_fr', 'like', '%mensuel%')
                    ->orWhere('name_fr', 'like', '%scolarite%')
                    ->orWhere('ledger_category', 'monthly_fee');
            })->first();

        if (! $feeType) {
            throw new \RuntimeException('لم يتم العثور على نوع المعلوم الشهري — تأكد من إعداد fee_types بشكل صحيح');
        }

        return $feeType;
    }

    /**
     * تنقيح وتطبيع رقم الهاتف إلى الصيغة القياسية (8 أرقام).
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $phone);
        if (empty($digits)) {
            return null;
        }
        if (str_starts_with($digits, '216') && strlen($digits) === 11) {
            $digits = substr($digits, 3);
        }
        if (str_starts_with($digits, '00216') && strlen($digits) === 13) {
            $digits = substr($digits, 5);
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 9) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) >= 8 ? substr($digits, -8) : ($digits ?: null);
    }

    /**
     * قائمة العائلات مع تجميع الأبناء بالاعتماد على رقم الهاتف الموحد وحساب إجمالي المستحقات.
     */
    public function listFamilies(?string $search = null, int $perPage = 25, int $page = 1): array
    {
        $activeYear = AcademicYear::where('is_active', true)->first()
            ?? AcademicYear::latest('start_date')->first();

        $academicMonths = $activeYear ? $this->collectionService->getAcademicYearMonths($activeYear) : [];

        // التحميل المسبق لخطط الرسوم حسب المستويات
        $feePlans = $activeYear ? FeePlan::where('academic_year_id', $activeYear->id)
            ->where('frequency', 'monthly')
            ->get()
            ->keyBy('level_id') : collect();

        // التحميل المسبق لربط النوادي بالأقسام والمستويات حسب إدارة النوادي
        $allActiveClubs = Club::with('sections')->where('is_active', true)->get();
        $sectionClubsMap = [];
        foreach ($allActiveClubs as $club) {
            foreach ($club->sections as $sec) {
                $sectionClubsMap[$sec->id][$club->id] = $club;
            }
        }

        // التحميل المسبق لاشتراكات واستثناءات النوادي الفردية
        $clubSubscriptionsByEnrollment = $activeYear ? ClubSubscription::where('academic_year_id', $activeYear->id)
            ->get()
            ->groupBy('enrollment_id') : collect();

        // التحميل المسبق لمعاليم النوادي غير الملغاة مفهرسة حسب التسجيل
        $clubFeesByEnrollment = $activeYear ? ClubMonthlyFee::where('academic_year_id', $activeYear->id)
            ->whereNull('cancelled_at')
            ->get()
            ->groupBy('enrollment_id') : collect();

        // التحميل المسبق للمدفوعات غير الملغاة مفهرسة حسب التسجيل
        $paymentsByEnrollment = Payment::whereNull('cancelled_at')
            ->get()
            ->groupBy('enrollment_id');

        // جلب معرفات الرسوم التي لها دفعات ملغاة — تُستثنى من المتخلدات
        $feesWithCancelledPayments = \App\Models\PaymentAllocation::query()
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereNotNull('payments.cancelled_at')
            ->whereNotNull('payment_allocations.student_fee_id')
            ->pluck('payment_allocations.student_fee_id')
            ->toArray();

        // جلب جميع التلاميذ مع تسجيلاتهم وتخفيضاتهم ورسومهم
        $studentsQuery = Student::query()
            ->with([
                'enrollments' => fn ($q) => $q->when($activeYear, fn ($eq) => $eq->where('academic_year_id', $activeYear->id))
                    ->with([
                        'section.level',
                        'monthlyDiscounts' => fn ($dq) => $dq->whereNull('cancelled_at'),
                        'discounts' => fn ($dq) => $dq->when($activeYear, fn ($yq) => $yq->where('academic_year_id', $activeYear->id))->whereNull('cancelled_at'),
                        'studentFees' => fn ($fq) => $fq->with(['paymentAllocations' => fn ($pq) => $pq->whereHas('payment', fn ($p) => $p->whereNull('cancelled_at'))]),
                    ]),
            ]);

        $allStudents = $studentsQuery->get();

        // تجميع التلاميذ في عائلات اعتماداً على رقم الهاتف المنقح
        $groupedFamilies = [];

        foreach ($allStudents as $student) {
            $phoneKey = self::normalizePhone($student->guardian_phone);
            if (! $phoneKey) {
                $phoneKey = self::normalizePhone($student->mother_phone);
            }

            // إذا لم يتوفر أي هاتف للتلميذ، يفرد له ملف عائلة مستقل بمعرفه
            $familyKey = $phoneKey ? 'phone_' . $phoneKey : 'student_' . $student->id;

            if (! isset($groupedFamilies[$familyKey])) {
                $gName = trim(($student->guardian_first_name ?? '') . ' ' . ($student->guardian_last_name ?? ''));
                if (empty($gName)) {
                    $gName = trim((string) ($student->mother_name ?? ''));
                }
                if (empty($gName)) {
                    $gName = 'ولي أمر (هاتف ' . ($phoneKey ?: '—') . ')';
                }

                $groupedFamilies[$familyKey] = [
                    'id' => $familyKey,
                    'guardian_name' => $gName,
                    'phone' => $phoneKey ?? ($student->guardian_phone ?: ($student->mother_phone ?: '—')),
                    'mother_name' => $student->mother_name ?: null,
                    'mother_phone' => self::normalizePhone($student->mother_phone) ?: ($student->mother_phone ?: null),
                    'students' => [],
                    'students_count' => 0,
                    'family_total_due' => 0.0,
                    'family_total_paid' => 0.0,
                    'family_remaining_debt' => 0.0,
                ];
            }

            // حساب المستحقات والمدفوعات والمتبقي للتلميذ
            $currentEnrollment = $student->enrollments->first();
            $studentDue = 0.0;
            $studentPaid = 0.0;
            $remainingDebt = 0.0;

            if ($currentEnrollment) {
                $levelId = $currentEnrollment->level_id ?? $currentEnrollment->section?->level_id;
                $feePlan = $feePlans->get($levelId);
                $baseAmount = (float) ($feePlan?->amount ?? 150.0);

                // 1. استخراج الأشهر المدفوعة للتلميذ
                $paidMonths = [];
                $enrollmentPayments = $paymentsByEnrollment->get($currentEnrollment->id, collect());
                foreach ($enrollmentPayments as $p) {
                    if (is_array($p->months)) {
                        foreach ($p->months as $pm) {
                            $paidMonths[] = $pm;
                        }
                    }
                }
                $paidMonths = array_unique($paidMonths);

                // 2. حساب معاليم الأشهر الدراسية (الـ 10 أشهر)
                foreach ($academicMonths as $m) {
                    if (in_array($m, $paidMonths, true)) {
                        $studentPaid += $baseAmount;
                        $studentDue += $baseAmount;
                    } else {
                        // فحص التخفيض الشهري أو السنوي
                        $monthlyDisc = $currentEnrollment->monthlyDiscounts->first(
                            fn ($d) => $d->start_month <= $m && $d->end_month >= $m
                        );
                        $netDue = $baseAmount;

                        if ($monthlyDisc) {
                            if ($monthlyDisc->discount_type === 'full_waiver') {
                                $netDue = 0.0;
                            } elseif (in_array($monthlyDisc->discount_type, ['humanitarian_fixed', 'normal_monthly'], true)) {
                                $netDue = max(0.0, round($baseAmount - (float) $monthlyDisc->monthly_amount, 2));
                            }
                        } else {
                            $annualDisc = $currentEnrollment->discounts->first();
                            if ($annualDisc && (float) $annualDisc->amount > 0) {
                                $netDue = max(0.0, round($baseAmount - (float) $annualDisc->amount, 2));
                            }
                        }

                        $studentDue += $netDue;
                        // القاعدة الذهبية: شهر مستقبلي لا يدخل في رقم الدين.
                        if ($m <= now()->format('Y-m')) {
                            $remainingDebt += $netDue;
                        }
                    }
                }

                // 3. حساب معاليم النوادي مربوطة بالقسم المسجل في إدارة النوادي (سبتمبر إلى ماي فقط — 9 أشهر)
                $secClubs = $sectionClubsMap[$currentEnrollment->section_id] ?? [];
                $enrSubs = $clubSubscriptionsByEnrollment->get($currentEnrollment->id, collect())->keyBy('club_id');
                $enrClubFees = $clubFeesByEnrollment->get($currentEnrollment->id, collect());

                foreach ($secClubs as $cId => $cObj) {
                    $sub = $enrSubs->get($cId);
                    // إذا كان التلميذ مستبعداً أو ملغى اشتراكه في هذا النادي
                    if ($sub && ($sub->excluded_at !== null || $sub->status === 'cancelled')) {
                        continue;
                    }
                    $monthlyClubFee = (float) ($sub?->monthly_fee_override ?? $cObj->monthly_fee ?? 20.0);

                    foreach (self::CLUB_MONTHS as $mNum) {
                        $mStr = ($mNum >= 9 ? '2026-' : '2027-') . str_pad($mNum, 2, '0', STR_PAD_LEFT);
                        $feeRec = $enrClubFees->first(fn ($f) => $f->club_id == $cId && $f->month == $mStr);

                        $cDue = $monthlyClubFee;
                        $cPaid = $feeRec ? (float) $feeRec->amount_paid : 0.0;
                        $cRem = max(0.0, round($cDue - $cPaid, 2));

                        $studentDue += $cDue;
                        $studentPaid += $cPaid;
                        // القاعدة الذهبية: شهر مستقبلي لا يدخل في رقم الدين.
                        if ($mStr <= now()->format('Y-m')) {
                            $remainingDebt += $cRem;
                        }
                    }
                }

                // 4. الرسوم المباشرة والمتخلدات السابقة الأخرى (إن وجدت)
                foreach ($currentEnrollment->studentFees as $sf) {
                    // استثناء رسوم النوادي — محسوبة مسبقاً
                    if ($sf->club_monthly_fee_id !== null) {
                        continue;
                    }
                    // استثناء رسوم التمدرس — محسوبة مسبقاً في شبكة الأشهر
                    if ($sf->fee_type_id !== null && $sf->feeType && str_contains(FeeType::normalize($sf->feeType->name_ar), 'تمدرس')) {
                        continue;
                    }
                    $sfDue = (float) $sf->amount_due;
                    $sfPaid = (float) $sf->amount_paid;
                    $sfRem = max(0.0, round($sfDue - $sfPaid, 2));
                    $studentDue += $sfDue;
                    $studentPaid += $sfPaid;
                    // الرسوم المدفوعة والملغاة لا تدخل في المتبقي
                    if ($sf->status === 'paid') {
                        continue;
                    }
                    if (in_array($sf->id, $feesWithCancelledPayments, true)) {
                        continue;
                    }
                    if ($sf->due_date && $sf->due_date->lte(now())) {
                        $remainingDebt += $sfRem;
                    }
                }
            }

            $remainingDebt = max(0.0, round($remainingDebt, 2));
            $studentPaid = round($studentPaid, 2);
            $studentDue = round($studentDue, 2);

            $groupedFamilies[$familyKey]['students'][] = [
                'id' => $student->id,
                'name' => trim($student->first_name . ' ' . $student->last_name),
                'student_code' => $student->student_code,
                'level_name' => $currentEnrollment?->section?->level?->name ?? '—',
                'section_name' => $currentEnrollment?->section?->name ?? '—',
                'remaining_debt' => $remainingDebt,
                'total_paid' => $studentPaid,
            ];

            $groupedFamilies[$familyKey]['students_count']++;
            $groupedFamilies[$familyKey]['family_total_due'] += $studentDue;
            $groupedFamilies[$familyKey]['family_total_paid'] += $studentPaid;
            $groupedFamilies[$familyKey]['family_remaining_debt'] += $remainingDebt;
        }

        // تحويل المصفوفة إلى Collection للفرز والبحث والـ Pagination
        $familiesCollection = collect(array_values($groupedFamilies));

        // التصفية بالبحث إن وُجد
        if (! empty($search)) {
            $searchClean = trim($search);
            $searchNormalized = self::normalizePhone($searchClean);

            $familiesCollection = $familiesCollection->filter(function ($fam) use ($searchClean, $searchNormalized) {
                if ($searchNormalized && str_contains(self::normalizePhone($fam['phone']) ?? '', $searchNormalized)) {
                    return true;
                }
                if ($searchNormalized && str_contains(self::normalizePhone($fam['mother_phone']) ?? '', $searchNormalized)) {
                    return true;
                }
                if (mb_stripos($fam['guardian_name'], $searchClean) !== false) {
                    return true;
                }
                if (! empty($fam['mother_name']) && mb_stripos($fam['mother_name'], $searchClean) !== false) {
                    return true;
                }
                foreach ($fam['students'] as $st) {
                    if (mb_stripos($st['name'], $searchClean) !== false || mb_stripos($st['student_code'] ?? '', $searchClean) !== false) {
                        return true;
                    }
                }

                return false;
            });
        }

        // الفرز: العائلات التي لديها عدة أبناء أولاً ثم حسب الاسم
        $sortedFamilies = $familiesCollection->sortByDesc('students_count')
            ->values();

        $total = $sortedFamilies->count();
        $offset = ($page - 1) * $perPage;
        $items = $sortedFamilies->slice($offset, $perPage)->values()->all();

        return [
            'data' => $items,
            'current_page' => $page,
            'last_page' => (int) ceil($total / max(1, $perPage)),
            'per_page' => $perPage,
            'total' => $total,
        ];
    }

    /**
     * جلب تفاصيل العائلة وأبنائها وشبكة الأشهر والنوادي والمتخلدات باستخدام CollectionService::preview.
     */
    public function getFamilyDetails(string|int $familyKey): array
    {
        $activeYear = AcademicYear::where('is_active', true)->first()
            ?? AcademicYear::latest('start_date')->firstOrFail();

        $guardianModel = null;
        if (is_numeric($familyKey)) {
            $guardianModel = Guardian::with('students')->find((int) $familyKey);
        }

        $students = $this->resolveFamilyStudents($familyKey, $activeYear, $guardianModel);

        if ($students->isEmpty()) {
            throw new ModelNotFoundException('العائلة غير موجودة');
        }

        $firstStudent = $students->first();
        $phone = $guardianModel?->phone
            ?: (self::normalizePhone($firstStudent->guardian_phone)
                ?: self::normalizePhone($firstStudent->mother_phone)
                ?: ($firstStudent->guardian_phone ?: '—'));

        $guardianName = $guardianModel
            ? trim($guardianModel->first_name . ' ' . $guardianModel->last_name)
            : trim(($firstStudent->guardian_first_name ?? '') . ' ' . ($firstStudent->guardian_last_name ?? ''));

        if (empty($guardianName)) {
            $guardianName = trim((string) ($firstStudent->mother_name ?? ''));
        }
        if (empty($guardianName)) {
            $guardianName = 'ولي أمر (هاتف ' . $phone . ')';
        }

        $academicMonths = $this->collectionService->getAcademicYearMonths($activeYear);
        $tuitionFeeType = self::findTuitionFeeType();
        $tuitionFeeTypeId = $tuitionFeeType->id;

        $studentsDetails = [];
        $familyTotalDue = 0.0;
        $familyTotalPaid = 0.0;
        $familyRemainingDebt = 0.0;

        foreach ($students as $student) {
            $enrollment = $student->enrollments->firstWhere('academic_year_id', $activeYear->id)
                ?? $student->enrollments->first();

            if (! $enrollment) {
                continue;
            }

            // 1. حساب شبكة الأشهر الدراسية الـ 10
            $paidMonths = $this->collectionService->getPaidMonths($enrollment->id);
            $monthLedger = $this->collectionService->monthLedger($enrollment->id);

            // جلب الخطة الشهرية للمستوى
            $levelId = $enrollment->level_id ?? $enrollment->section?->level_id;
            $feePlan = FeePlan::where('academic_year_id', $activeYear->id)
                ->where('level_id', $levelId)
                ->first();
            $baseMonthlyAmount = (float) ($feePlan?->amount ?? 150.0);

            $monthsGrid = [];
            foreach ($academicMonths as $m) {
                $monthNum = (int) substr($m, 5, 2);
                $monthNameAr = self::MONTH_NAMES_AR[substr($m, 5, 2)] ?? $m;
                $isPaid = in_array($m, $paidMonths, true);

                if ($isPaid) {
                    $paymentInfo = $monthLedger[$m] ?? null;
                    $paidAmt = (float) ($paymentInfo['amount'] ?? $baseMonthlyAmount);
                    $monthsGrid[] = [
                        'month' => $m,
                        'month_number' => $monthNum,
                        'name_ar' => $monthNameAr,
                        'status' => 'paid',
                        'gross_amount' => $baseMonthlyAmount,
                        'discount_amount' => 0.0,
                        'net_amount' => $paidAmt,
                        'paid_amount' => $paidAmt,
                        'payment_info' => $paymentInfo,
                    ];
                    $familyTotalPaid += $paidAmt;
                    $familyTotalDue += $paidAmt;
                } else {
                    // استخدام CollectionService::preview لحساب التخفيضات والإعفاءات بدقة
                    $preview = $this->collectionService->preview($enrollment->id, [$m], $tuitionFeeTypeId);
                    $netDue = (float) ($preview['remaining_amount'] ?? $baseMonthlyAmount);
                    $discountAmt = max(0.0, round($baseMonthlyAmount - $netDue, 2));
                    $isWaived = (bool) ($preview['is_fully_waived'] ?? false);

                    $monthsGrid[] = [
                        'month' => $m,
                        'month_number' => $monthNum,
                        'name_ar' => $monthNameAr,
                        'status' => $isWaived ? 'waived' : ($netDue <= 0 ? 'waived' : 'unpaid'),
                        'gross_amount' => $baseMonthlyAmount,
                        'discount_amount' => $discountAmt,
                        'net_amount' => $netDue,
                        'paid_amount' => 0.0,
                        'payment_info' => null,
                    ];

                    $familyTotalDue += $netDue;
                    // القاعدة الذهبية: شهر مستقبلي لا يدخل في رقم الدين.
                    if ($m <= now()->format('Y-m')) {
                        $familyRemainingDebt += $netDue;
                    }
                }
            }

            // 2. حساب نوادي التلميذ (سبتمبر -> ماي فقط، جوان مستثنى)
            $clubsList = [];
            $this->clubService->ensureFeesForEnrollment($enrollment, $academicMonths, (int) auth()->id());

            $clubMonthlyFees = ClubMonthlyFee::with(['club', 'studentFee'])
                ->where('enrollment_id', $enrollment->id)
                ->whereNull('cancelled_at')
                ->get()
                ->groupBy('club_id');

            foreach ($clubMonthlyFees as $cId => $feesGroup) {
                $clubObj = $feesGroup->first()->club;
                $clubMonthsGrid = [];

                foreach ($academicMonths as $m) {
                    $mNum = (int) substr($m, 5, 2);
                    // النوادي: سبتمبر إلى ماي فقط (9 أشهر)
                    if (! in_array($mNum, self::CLUB_MONTHS, true)) {
                        continue;
                    }

                    $feeRec = $feesGroup->firstWhere('month', $m);
                    $cMonthName = self::MONTH_NAMES_AR[substr($m, 5, 2)] ?? $m;

                    if ($feeRec) {
                        $cDue = (float) $feeRec->amount_due;
                        $cPaid = (float) $feeRec->amount_paid;
                        $cRemaining = max(0.0, round($cDue - $cPaid, 2));
                        $cStatus = $cRemaining <= 0 ? 'paid' : ($cPaid > 0 ? 'partial' : 'unpaid');

                        $clubMonthsGrid[] = [
                            'club_monthly_fee_id' => $feeRec->id,
                            'month' => $m,
                            'name_ar' => $cMonthName,
                            'amount_due' => $cDue,
                            'amount_paid' => $cPaid,
                            'remaining_amount' => $cRemaining,
                            'status' => $cStatus,
                        ];

                        $familyTotalDue += $cDue;
                        $familyTotalPaid += $cPaid;
                        // القاعدة الذهبية: شهر مستقبلي لا يدخل في رقم الدين.
                        if ($m <= now()->format('Y-m')) {
                            $familyRemainingDebt += $cRemaining;
                        }
                    }
                }

                $clubsList[] = [
                    'club_id' => $clubObj?->id ?? $cId,
                    'club_name' => $clubObj?->name ?? 'نادي',
                    'monthly_fee' => (float) ($clubObj?->monthly_fee ?? 0),
                    'months' => $clubMonthsGrid,
                ];
            }

            // 3. حساب المتخلدات والديون السابقة
            // القاعدة الذهبية: شهر مستقبلي (due_date > اليوم) ليس متخلداً؛
            // وdue_date فارغ لا يُعتبر متخلداً.
            $priorArrears = [];
            $unpaidFees = [];
            $oldFees = StudentFee::where('enrollment_id', $enrollment->id)
                ->where('status', '!=', 'paid')
                ->whereNull('club_monthly_fee_id')
                ->where(function ($q) use ($tuitionFeeTypeId) {
                    $q->whereNull('fee_type_id')
                        ->orWhere('fee_type_id', '!=', $tuitionFeeTypeId);
                })
                ->whereDate('due_date', '<=', now())
                ->whereNotExists(function ($q) {
                    // استثناء أي رسم له دفعة ملغاة — الإلغاء لا يحوّل الرسم إلى متخلد
                    $q->select(DB::raw(1))
                        ->from('payment_allocations')
                        ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
                        ->whereColumn('payment_allocations.student_fee_id', 'student_fees.id')
                        ->whereNotNull('payments.cancelled_at');
                })
                ->get();

            foreach ($oldFees as $oldFee) {
                $rem = $oldFee->outstanding();
                if ($rem > 0) {
                    $item = [
                        'id' => $oldFee->id,
                        'student_fee_id' => $oldFee->id,
                        'fee_type_id' => $oldFee->fee_type_id ?? 0,
                        'description' => $oldFee->description ?: 'متخلد سابق',
                        'amount_due' => (float) $oldFee->amount_due,
                        'amount_paid' => (float) $oldFee->amount_paid,
                        'gross_amount' => (float) $oldFee->amount_due,
                        'paid_amount' => (float) $oldFee->amount_paid,
                        'remaining_amount' => round($rem, 2),
                        'status' => $oldFee->status,
                    ];
                    $priorArrears[] = $item;
                    $unpaidFees[] = $item;
                    // القاعدة الذهبية: هذه متخلدات حقيقية (استحقاقها مضى ولم تُدفع)
                    // فتدخل في إجمالي المتبقي بالذمة للعائلة.
                    $familyRemainingDebt += $rem;
                }
            }

            $studentsDetails[] = [
                'id' => $student->id,
                'student_id' => $student->id,
                'enrollment_id' => $enrollment->id,
                'student_code' => $student->student_code,
                'first_name' => $student->first_name,
                'last_name' => $student->last_name,
                'name' => trim($student->first_name . ' ' . $student->last_name),
                'full_name' => trim($student->first_name . ' ' . $student->last_name),
                'level_name' => $enrollment->level?->name ?? $enrollment->section?->level?->name ?? '—',
                'section_name' => $enrollment->section?->name ?? '—',
                'base_monthly_fee' => $baseMonthlyAmount,
                'remaining_debt' => round(array_sum(array_column($priorArrears, 'remaining_amount')), 2),
                'total_paid' => (float) $student->payments->sum('amount'),
                'months_grid' => $monthsGrid,
                'clubs' => $clubsList,
                'arrears' => $priorArrears,
                'unpaid_fees' => $unpaidFees,
            ];
        }

        $availableClubs = Club::where('is_active', true)
            ->select('id', 'name', 'monthly_fee')
            ->orderBy('name')
            ->get();

        $availableFeeTypes = FeeType::where('is_active', true)
            ->select('id', 'name_ar', 'price', 'ledger_category')
            ->orderBy('name_ar')
            ->get();

        $resolvedId = $guardianModel?->id ?? (is_numeric($familyKey) ? (int) $familyKey : $familyKey);

        $familyData = [
            'id' => $resolvedId,
            'guardian_name' => $guardianName,
            'phone' => $phone,
            'address' => $guardianModel?->address ?? null,
            'mother_name' => $firstStudent->mother_name ?: null,
            'mother_phone' => self::normalizePhone($firstStudent->mother_phone) ?: ($firstStudent->mother_phone ?: null),
            'students_count' => count($studentsDetails),
            'total_due' => round($familyTotalDue, 2),
            'total_paid' => round($familyTotalPaid, 2),
            'remaining_debt' => round($familyRemainingDebt, 2),
            'family_total_due' => round($familyTotalDue, 2),
            'family_total_paid' => round($familyTotalPaid, 2),
            'family_remaining_debt' => round($familyRemainingDebt, 2),
            'students' => $studentsDetails,
            'available_clubs' => $availableClubs,
            'available_fee_types' => $availableFeeTypes,
        ];

        return array_merge($familyData, [
            'family' => $familyData,
        ]);
    }

    /**
     * استخلاص جماعي للعائلة: كل عملية تمر حصراً عبر CollectionService::collect()
     * لتسجيل Payment والتأثير في الخزينة عبر LedgerService::recordPayment().
     */
    public function collectFamilyPayment(array $data, int $userId): array
    {
        Log::info('FamilyService::collectFamilyPayment started', [
            'family_id' => $data['family_id'] ?? null,
            'user_id' => $userId,
            'payload' => $data,
        ]);

        return DB::transaction(function () use ($data, $userId) {
            $allocationsByStudent = $data['students_allocations'] ?? [];
            $flatAllocations = $data['allocations'] ?? [];

            $paymentDate = $data['payment_date'] ?? now()->toDateString();
            $method = $data['method'] ?? 'cash';
            $reference = $data['reference'] ?? null;
            $notes = $data['notes'] ?? null;

            // تحويل الصيغة المسطحة (allocations) إن وُجدت إلى students_allocations
            if (empty($allocationsByStudent) && ! empty($flatAllocations)) {
                $grouped = [];
                foreach ($flatAllocations as $alloc) {
                    $stId = (int) ($alloc['student_id'] ?? 0);
                    $feeId = (int) ($alloc['student_fee_id'] ?? 0);
                    $amt = (float) ($alloc['amount'] ?? 0);

                    if (! isset($grouped[$stId])) {
                        $studentObj = Student::with('enrollments')->find($stId);
                        $enId = (int) ($alloc['new_item']['enrollment_id'] ?? ($studentObj?->enrollments?->first()?->id ?? 0));
                        $grouped[$stId] = [
                            'student_id' => $stId,
                            'enrollment_id' => $enId,
                            'months' => [],
                            'items' => [],
                            'club_items' => [],
                            'prior_allocations' => [],
                        ];
                    }

                    if ($feeId > 0 && $amt > 0) {
                        $fee = StudentFee::find($feeId);
                        if ($fee && ($fee->outstanding() <= 0 || $fee->status === 'paid')) {
                            throw new InvalidArgumentException('البند المحدد تم استخلاصه بالكامل مسبقاً');
                        }
                        $targetEnrollment = Enrollment::find($grouped[$stId]['enrollment_id']);
                        if ($fee && $targetEnrollment && (int) $fee->enrollment?->academic_year_id !== (int) $targetEnrollment->academic_year_id) {
                            $grouped[$stId]['prior_allocations'][] = [
                                'student_fee_id' => $feeId,
                                'amount' => $amt,
                            ];
                        } else {
                            $fTypeId = $fee?->fee_type_id ?? self::findTuitionFeeType()->id;
                            $grouped[$stId]['items'][] = [
                                'fee_type_id' => $fTypeId,
                                'description' => $fee?->description ?? 'معلوم مستحق',
                                'amount' => $amt,
                            ];
                        }
                    } elseif (! empty($alloc['new_item'])) {
                        $newItem = $alloc['new_item'];
                        $fTypeId = ! empty($newItem['fee_type_id']) ? (int) $newItem['fee_type_id'] : self::findTuitionFeeType()->id;
                        $grouped[$stId]['items'][] = [
                            'fee_type_id' => $fTypeId,
                            'description' => $newItem['description'] ?? 'معلوم إضافي',
                            'amount' => (float) ($newItem['amount_due'] ?? $amt),
                        ];
                    }
                }
                $allocationsByStudent = array_values($grouped);
            }

            if (empty($allocationsByStudent)) {
                throw new InvalidArgumentException('يجب تحديد مستحقات للاستخلاص عن تلميذ واحد على الأقل');
            }

            $tuitionFeeType = self::findTuitionFeeType();
            $tuitionFeeTypeId = $tuitionFeeType->id;

            $familyReceipts = [];
            $familyTotalCollected = 0.0;
            $familyItemsSummary = [];
            $siblingReceiptsList = [];

            foreach ($allocationsByStudent as $studentAlloc) {
                $studentId = (int) ($studentAlloc['student_id'] ?? 0);
                $enrollmentId = (int) ($studentAlloc['enrollment_id'] ?? 0);
                $months = (array) ($studentAlloc['months'] ?? []);
                $clubItems = (array) ($studentAlloc['club_items'] ?? []);
                $priorAllocations = (array) ($studentAlloc['prior_allocations'] ?? []);
                $customItems = (array) ($studentAlloc['items'] ?? []);

                if (empty($months) && empty($clubItems) && empty($priorAllocations) && empty($customItems)) {
                    continue;
                }

                $enrollment = Enrollment::with('student', 'section.level', 'academicYear')->findOrFail($enrollmentId);

                $items = $customItems;
                if (! empty($months)) {
                    $preview = $this->collectionService->preview($enrollment->id, $months, $tuitionFeeTypeId);
                    $tuitionAmount = (float) $preview['remaining_amount'];

                    $items[] = [
                        'fee_type_id' => $tuitionFeeTypeId,
                        'description' => 'معلوم دراسي شهر ' . implode(' / ', array_map(fn ($m) => self::MONTH_NAMES_AR[substr($m, 5)] ?? $m, $months)),
                        'amount' => $tuitionAmount,
                    ];
                }

                $collectPayload = [
                    'student_id' => $studentId,
                    'enrollment_id' => $enrollmentId,
                    'months' => $months,
                    'items' => $items,
                    'club_items' => $clubItems,
                    'prior_allocations' => $priorAllocations,
                    'payment_date' => $paymentDate,
                    'method' => $method,
                    'reference' => $reference,
                    'notes' => $notes,
                    'idempotency_key' => ! empty($data['idempotency_key']) ? $data['idempotency_key'] . '_st_' . $studentId : null,
                ];

                Log::info('FamilyService dispatching child payment to CollectionService::collect', [
                    'student_id' => $studentId,
                    'payload' => $collectPayload,
                ]);

                // كل عملية استخلاص تمر حصراً ومباشرة عبر CollectionService::collect()
                $receipt = $this->collectionService->collect($collectPayload, $userId);

                $familyReceipts[] = $receipt;
                $familyTotalCollected += (float) ($receipt['total'] ?? 0);

                $stName = $enrollment->student->first_name . ' ' . $enrollment->student->last_name;
                // دمج items + club_items في قائمة واحدة للعرض في الوصل
                $allReceiptItems = array_merge(
                    $receipt['items'] ?? [],
                    array_map(fn ($ci) => [
                        'description' => ($ci['club_name'] ?? 'نادي') . ' — ' . ($ci['month_label'] ?? $ci['month'] ?? ''),
                        'amount' => $ci['amount'] ?? $ci['amount_due'] ?? 0,
                    ], $receipt['club_items'] ?? [])
                );

                $siblingReceiptsList[] = [
                    'student_id' => $studentId,
                    'student_name' => $stName,
                    'student_code' => $enrollment->student->student_code,
                    'level_section' => ($enrollment->section?->level?->name ?? '') . ' — ' . ($enrollment->section?->name ?? ''),
                    'payment_id' => $receipt['payment_id'] ?? null,
                    'receipt_number' => $receipt['receipt_number'] ?? null,
                    'months' => $months,
                    'amount' => (float) ($receipt['total'] ?? 0),
                    'items' => $allReceiptItems,
                ];

                foreach ($receipt['items'] ?? [] as $it) {
                    $familyItemsSummary[] = [
                        'student_name' => $stName,
                        'description' => $it['description'] ?? '',
                        'amount' => (float) ($it['amount'] ?? 0),
                    ];
                }
            }

            if (empty($siblingReceiptsList)) {
                throw new InvalidArgumentException('لم يتم استخلاص أي مبلغ عن أي تلميذ');
            }

            $firstReceipt = $familyReceipts[0];
            $familyReceiptNumber = 'FAM-' . ($firstReceipt['receipt_number'] ?? date('YmdHis'));

            $unifiedFamilyReceipt = [
                'is_family_receipt' => true,
                'family_receipt_number' => $familyReceiptNumber,
                'receipt_number' => $familyReceiptNumber,
                'payment_id' => $firstReceipt['payment_id'] ?? null,
                'payment_date' => $paymentDate,
                'method' => $method,
                'reference' => $reference,
                'notes' => $notes,
                'total' => round($familyTotalCollected, 2),
                'guardian_name' => $data['guardian_name'] ?? ($firstReceipt['guardian']['first_name'] ?? 'ولي أمر'),
                'guardian_phone' => $data['guardian_phone'] ?? ($firstReceipt['guardian']['phone'] ?? ''),
                'siblings' => $siblingReceiptsList,
                'items' => $familyItemsSummary,
                'created_by' => $firstReceipt['created_by'] ?? null,
            ];

            Log::info('FamilyService::collectFamilyPayment completed successfully', [
                'receipt_number' => $familyReceiptNumber,
                'total' => $familyTotalCollected,
                'siblings_count' => count($siblingReceiptsList),
            ]);

            return $unifiedFamilyReceipt;
        });
    }

    /**
     * إيجاد تلاميذ العائلة بناء على المعرف (رقم هاتف منقح أو معرف تلميذ أو Guardian).
     */
    protected function resolveFamilyStudents(string|int $familyKey, AcademicYear $activeYear, ?Guardian $guardianModel = null)
    {
        if ($guardianModel && $guardianModel->students->isNotEmpty()) {
            return $guardianModel->students()
                ->with([
                    'enrollments' => fn ($eq) => $eq->where('academic_year_id', $activeYear->id)->with(['section.level']),
                    'payments' => fn ($pq) => $pq->whereNull('cancelled_at'),
                ])
                ->get();
        }

        $phoneKey = str_replace('phone_', '', (string) $familyKey);
        $phoneDigits = self::normalizePhone($phoneKey);

        if ($phoneDigits) {
            $all = Student::query()
                ->with([
                    'enrollments' => fn ($eq) => $eq->where('academic_year_id', $activeYear->id)->with(['section.level']),
                    'payments' => fn ($pq) => $pq->whereNull('cancelled_at'),
                ])
                ->get();

            return $all->filter(function (Student $st) use ($phoneDigits) {
                return self::normalizePhone($st->guardian_phone) === $phoneDigits
                    || self::normalizePhone($st->mother_phone) === $phoneDigits;
            })->values();
        }

        if (str_starts_with((string) $familyKey, 'student_') || is_numeric($familyKey)) {
            $stId = (int) str_replace('student_', '', (string) $familyKey);

            return Student::whereKey($stId)
                ->with([
                    'enrollments' => fn ($eq) => $eq->where('academic_year_id', $activeYear->id)->with(['section.level']),
                    'payments' => fn ($pq) => $pq->whereNull('cancelled_at'),
                ])
                ->get();
        }

        return collect();
    }
}
