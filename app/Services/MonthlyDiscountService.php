<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\MonthlyDiscount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MonthlyDiscountService
{
    /**
     * Create a recurring monthly discount for tuition (full_waiver or humanitarian_fixed).
     */
    public function createDiscount(
        int $enrollmentId,
        string $discountType,
        ?float $monthlyAmount,
        string $reason,
        ?string $notes = null,
        ?int $createdBy = null,
        ?string $startMonth = null,
        ?string $endMonth = null
    ): MonthlyDiscount {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('سبب التخفيض إجباري');
        }

        if (! in_array($discountType, [MonthlyDiscount::TYPE_FULL_WAIVER, MonthlyDiscount::TYPE_HUMANITARIAN_FIXED, MonthlyDiscount::TYPE_NORMAL_MONTHLY], true)) {
            throw new InvalidArgumentException('نوع التخفيض غير صحيح');
        }

        return DB::transaction(function () use ($enrollmentId, $discountType, $monthlyAmount, $reason, $notes, $createdBy, $startMonth, $endMonth) {
            $enrollment = Enrollment::whereKey($enrollmentId)->lockForUpdate()->firstOrFail();
            $academicYear = AcademicYear::findOrFail($enrollment->academic_year_id);

            $startMonth = $startMonth ?? Carbon::parse($academicYear->start_date)->format('Y-m'); // e.g. 2025-09
            $endMonth   = $endMonth ?? Carbon::parse($academicYear->end_date)->format('Y-m');   // e.g. 2026-06

            // Determine monthly tuition fee from FeePlan
            $monthlyFee = (float) FeePlan::query()
                ->where('academic_year_id', $academicYear->id)
                ->where('level_id', $enrollment->level_id)
                ->where('frequency', 'monthly')
                ->sum('amount');

            if ($monthlyFee <= 0) {
                throw new InvalidArgumentException('تعذّر حساب المعلوم الشهري: لا يوجد مخطط رسوم لمستوى هذا التسجيل في هذه السنة الدراسية');
            }

            if ($discountType === MonthlyDiscount::TYPE_NORMAL_MONTHLY) {
                if ($monthlyAmount === null || $monthlyAmount <= 0) {
                    throw new InvalidArgumentException('مبلغ التخفيض الشهري العادي يجب أن يكون أكبر من الصفر');
                }
                $maxCap = round($monthlyFee * 0.20, 2);
                if ($monthlyAmount > $maxCap) {
                    throw new InvalidArgumentException("مبلغ التخفيض الشهري العادي ({$monthlyAmount}) يتجاوز الحد الأقصى المسموح به 20% ({$maxCap}) من المعلوم الشهري");
                }
                $finalAmount = round($monthlyAmount, 2);
            } elseif ($discountType === MonthlyDiscount::TYPE_HUMANITARIAN_FIXED) {
                if ($monthlyAmount === null || $monthlyAmount <= 20.0) {
                    throw new InvalidArgumentException('مبلغ التخفيض الإنساني يجب أن يكون أكبر من 20 ديناراً');
                }
                if ($monthlyAmount > $monthlyFee) {
                    throw new InvalidArgumentException("مبلغ التخفيض الإنساني ({$monthlyAmount}) لا يمكن أن يتجاوز المعلوم الشهري ({$monthlyFee})");
                }
                $finalAmount = round($monthlyAmount, 2);
            } else {
                // full_waiver
                $finalAmount = null;
            }


            // Check if ANY active recurring monthly discount exists for this enrollment, year, fee category & overlapping month range
            $hasActiveOverlap = MonthlyDiscount::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('academic_year_id', $academicYear->id)
                ->where('fee_category', 'tuition')
                ->active()
                ->where(function ($q) use ($startMonth, $endMonth) {
                    $q->where('start_month', '<=', $endMonth)
                      ->where('end_month', '>=', $startMonth);
                })
                ->exists();

            if ($hasActiveOverlap) {
                throw new InvalidArgumentException('يوجد تخفيض سارٍ بالفعل يتداخل مع هذه الفترة لهذا التسجيل؛ يرجى إيقاف أو إلغاء التخفيض السابق أولاً');
            }


            return MonthlyDiscount::create([
                'enrollment_id'    => $enrollment->id,
                'academic_year_id' => $academicYear->id,
                'discount_type'    => $discountType,
                'monthly_amount'   => $finalAmount,
                'fee_category'     => 'tuition',
                'start_month'      => $startMonth,
                'end_month'        => $endMonth,
                'reason'           => $reason,
                'notes'            => $notes,
                'created_by'       => $createdBy,
            ]);
        });
    }

    /**
     * Cancel an active monthly tuition discount.
     */
    public function cancel(int $discountId, string $reason, ?int $cancelledBy = null): MonthlyDiscount
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('سبب الإلغاء إجباري');
        }

        return DB::transaction(function () use ($discountId, $reason, $cancelledBy) {
            $discount = MonthlyDiscount::whereKey($discountId)->lockForUpdate()->firstOrFail();

            if ($discount->isCancelled()) {
                throw new InvalidArgumentException('هذا التخفيض ملغى مسبقاً');
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
     * Get active tuition monthly discount for enrollment & month.
     */
    public function getActiveDiscountForMonth(int $enrollmentId, int $academicYearId, string $month): ?MonthlyDiscount
    {
        return MonthlyDiscount::query()
            ->where('enrollment_id', $enrollmentId)
            ->where('academic_year_id', $academicYearId)
            ->active()
            ->where('start_month', '<=', $month)
            ->where('end_month', '>=', $month)
            ->first();
    }
}
