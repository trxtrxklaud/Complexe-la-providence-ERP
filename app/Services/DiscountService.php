<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\EnrollmentDiscount;
use App\Models\FeePlan;
use Illuminate\Support\Facades\DB;

/**
 * التخفيض السنوي على معاليم التلميذ — سعر مخفّض لا دَين ولا متخلّد.
 *
 * قاعدة السقف: لا يتجاوز التخفيض 20% من مجموع معاليم السنة. وأساس المجموع
 * هو مخطّط الرسوم (FeePlan) لا الرسوم المُنشأة عند الاستخلاص، لأنّ هذه الأخيرة
 * تتولّد شهراً بشهر فلا تكتمل وقت منح التخفيض (سبتمبر عادةً). المخطّط ثابت ومعلوم
 * مسبقاً، فيعطي سقفاً لا يتحرّك: القسط الشهري × عدد أشهر السنة + الرسوم السنوية.
 *
 * تخفيض واحد سارٍ لكل تسجيل في السنة الواحدة. الإلغاء يُبقي الأثر ولا يحذفه.
 */
class DiscountService
{
    /** السنة الدراسية عشرة أشهر: سبتمبر → جوان. مطابق CollectionService و ClassroomRosterController. */
    private const SCHOOL_MONTHS_COUNT = 10;

    /** السقف: 20% من مجموع معاليم السنة. */
    public const MAX_DISCOUNT_RATE = 0.20;

    /**
     * منح تخفيض سنوي لتسجيل. السنة الدراسية تُشتقّ من التسجيل نفسه لا تُمرّر،
     * فلا يقع تخفيض في سنة لا يخصّها التسجيل.
     */
    public function createForEnrollment(
        int $enrollmentId,
        float $amount,
        string $reason,
        string $appliedDate,
        ?int $createdBy = null
    ): EnrollmentDiscount {
        $reason = trim($reason);

        if ($reason === '') {
            throw new \InvalidArgumentException('سبب التخفيض إجباري');
        }

        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ التخفيض يجب أن يكون أكبر من صفر');
        }

        return DB::transaction(function () use ($enrollmentId, $amount, $reason, $appliedDate, $createdBy) {
            // قفل صف التسجيل يُسلسِل منح التخفيضات المتزامنة لنفس التسجيل،
            // فلا يمرّ تخفيضان معاً فيتجاوزا السقف أو يكسرا قاعدة الواحد السارِي.
            $enrollment = Enrollment::whereKey($enrollmentId)->lockForUpdate()->firstOrFail();

            $yearId = (int) $enrollment->academic_year_id;

            // تخفيض واحد سارٍ لكل تسجيل في السنة الواحدة.
            $hasActive = EnrollmentDiscount::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('academic_year_id', $yearId)
                ->whereNull('cancelled_at')
                ->exists();

            if ($hasActive) {
                throw new \InvalidArgumentException(
                    'يوجد تخفيض سارٍ لهذا التسجيل في هذه السنة؛ ألغه أولاً قبل منح تخفيض جديد'
                );
            }

            // فحص السقف داخل القفل: أساسه معاليم السنة من المخطّط.
            $annualFees = $this->calculateAnnualFees($enrollment);
            $this->assertWithinCap($annualFees, 0.0, $amount);

            $percentage = $annualFees > 0
                ? round(($amount / $annualFees) * 100, 2)
                : null;

            return EnrollmentDiscount::create([
                'enrollment_id'    => $enrollment->id,
                'academic_year_id' => $yearId,
                'amount'           => $amount,
                'percentage'       => $percentage,
                'reason'           => $reason,
                'applied_date'     => $appliedDate,
                'created_by'       => $createdBy,
            ]);
        });
    }

    /**
     * مجموع التخفيضات السارية لتسجيل في سنة بعينها.
     * الملغى لا يُحتسب: getEffectiveAmount في النموذج يعيد صفراً له.
     */
    public function getTotalForEnrollment(int $enrollmentId, int $academicYearId): float
    {
        return round((float) EnrollmentDiscount::query()
            ->where('enrollment_id', $enrollmentId)
            ->where('academic_year_id', $academicYearId)
            ->whereNull('cancelled_at')
            ->sum('amount'), 2);
    }

    /**
     * التحقق من سقف 20%: التخفيضات السارية الحالية + المقترح لا تتجاوز السقف.
     * يُرمى استثناء واضح بالأرقام إن تُجووِز، وإلا يمرّ بلا قيمة.
     */
    public function validate20PercentCap(int $enrollmentId, float $proposedAmount): void
    {
        $enrollment = Enrollment::findOrFail($enrollmentId);

        $annualFees   = $this->calculateAnnualFees($enrollment);
        $existingTotal = $this->getTotalForEnrollment($enrollmentId, (int) $enrollment->academic_year_id);

        $this->assertWithinCap($annualFees, $existingTotal, round($proposedAmount, 2));
    }

    /**
     * إلغاء تخفيض: يعود المستحقّ كما كان قبله، ويبقى أثر التخفيض وإلغائه مقروءاً.
     */
    public function cancel(int $discountId, string $reason, ?int $cancelledBy = null): EnrollmentDiscount
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new \InvalidArgumentException('سبب الإلغاء إجباري');
        }

        return DB::transaction(function () use ($discountId, $reason, $cancelledBy) {
            $discount = EnrollmentDiscount::whereKey($discountId)->lockForUpdate()->firstOrFail();

            if ($discount->isCancelled()) {
                throw new \InvalidArgumentException('هذا التخفيض ملغى مسبقاً');
            }

            $discount->update([
                'cancelled_at'        => now(),
                'cancelled_by'        => $cancelledBy,
                'cancellation_reason' => $reason,
            ]);

            return $discount->refresh();
        });
    }

    /**
     * مجموع معاليم السنة من المخطّط: القسط الشهري × عدد أشهر السنة + الرسوم السنوية.
     * أساس ثابت لسقف التخفيض، معلوم وقت المنح قبل أن تتولّد رسوم الأشهر.
     *
     * عام لا خاص: التقارير قد تحتاج المجموع نفسه لعرض «المعاليم قبل التخفيض».
     */
    public function calculateAnnualFees(Enrollment $enrollment): float
    {
        if ($enrollment->level_id === null || $enrollment->academic_year_id === null) {
            return 0.0;
        }

        $plans = FeePlan::query()
            ->where('academic_year_id', $enrollment->academic_year_id)
            ->where('level_id', $enrollment->level_id)
            ->get(['amount', 'frequency']);

        $monthly = round((float) $plans->where('frequency', 'monthly')->sum('amount'), 2);
        $yearly  = round((float) $plans->where('frequency', 'yearly')->sum('amount'), 2);

        return round($monthly * self::SCHOOL_MONTHS_COUNT + $yearly, 2);
    }

    /**
     * السقف المطلق للتخفيض على تسجيل = 20% من معاليم سنته.
     */
    public function capForEnrollment(Enrollment $enrollment): float
    {
        $annualFees = $this->calculateAnnualFees($enrollment);
        if ($annualFees <= 0) {
            return 0.0;
        }

        return round(($annualFees / self::SCHOOL_MONTHS_COUNT) * self::MAX_DISCOUNT_RATE, 2);
    }

    /**
     * حارس السقف المشترك: يُستعمل عند المنح وعند التحقق المسبق معاً،
     * فلا يفترق منطق الرفض بين المسارين.
     */
    private function assertWithinCap(float $annualFees, float $existingTotal, float $proposedAmount): void
    {
        if ($annualFees <= 0) {
            throw new \InvalidArgumentException(
                'تعذّر حساب سقف التخفيض: لا يوجد مخطّط رسوم لمستوى هذا التسجيل في هذه السنة'
            );
        }

        $monthlyFee   = $annualFees / self::SCHOOL_MONTHS_COUNT;
        $monthlyCap   = round($monthlyFee * self::MAX_DISCOUNT_RATE, 2);
        $totalMonthly = round($existingTotal + $proposedAmount, 2);

        // هامش مليم واحد يمتصّ فروق التقريب فلا يُرفض تخفيض مساوٍ للسقف تماماً.
        if ($totalMonthly > $monthlyCap + 0.001) {
            throw new \InvalidArgumentException(
                'التخفيض الشهري (' . $totalMonthly . ') يتجاوز السقف المسموح (' . $monthlyCap
                . ') وهو 20% من المعلوم الشهري (' . round($monthlyFee, 2) . ')'
            );
        }
    }

}
