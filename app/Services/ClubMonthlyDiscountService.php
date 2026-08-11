<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\ClubMonthlyDiscount;
use App\Models\ClubSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ClubMonthlyDiscountService
{
    /**
     * Create a recurring monthly discount for a club subscription.
     */
    public function createDiscount(
        int $subscriptionId,
        string $discountType,
        ?float $monthlyAmount,
        string $reason,
        ?string $notes = null,
        ?int $createdBy = null,
        ?string $startMonth = null,
        ?string $endMonth = null
    ): ClubMonthlyDiscount {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('سبب التخفيض إجباري');
        }

        if (! in_array($discountType, [ClubMonthlyDiscount::TYPE_FULL_WAIVER, ClubMonthlyDiscount::TYPE_HUMANITARIAN_FIXED], true)) {
            throw new InvalidArgumentException('نوع التخفيض غير صحيح');
        }

        return DB::transaction(function () use ($subscriptionId, $discountType, $monthlyAmount, $reason, $notes, $createdBy, $startMonth, $endMonth) {
            $subscription = ClubSubscription::with(['club', 'academicYear'])->whereKey($subscriptionId)->lockForUpdate()->firstOrFail();
            $academicYear = $subscription->academicYear ?? AcademicYear::findOrFail($subscription->academic_year_id);

            $startMonth = $startMonth ?? Carbon::parse($academicYear->start_date)->format('Y-m'); // e.g. 2025-09
            $endMonth   = $endMonth ?? Carbon::parse($academicYear->end_date)->format('Y-m');   // e.g. 2026-06

            $clubFee = $subscription->monthly_fee_override !== null
                ? (float) $subscription->monthly_fee_override
                : (float) ($subscription->club?->monthly_fee ?? 0.0);

            if ($discountType === ClubMonthlyDiscount::TYPE_HUMANITARIAN_FIXED) {
                if ($monthlyAmount === null || $monthlyAmount <= 20.0) {
                    throw new InvalidArgumentException('مبلغ التخفيض الإنساني للنوادي يجب أن يكون أكبر من 20 ديناراً');
                }
                if ($clubFee > 0 && $monthlyAmount > $clubFee) {
                    throw new InvalidArgumentException("مبلغ التخفيض الإنساني ({$monthlyAmount}) لا يمكن أن يتجاوز معلوم النادي الشهري ({$clubFee})");
                }
                $finalAmount = round($monthlyAmount, 2);
            } else {
                // full_waiver
                $finalAmount = null;
            }

            // Check if ANY active monthly discount exists for this subscription & overlapping month range
            $hasActiveOverlap = ClubMonthlyDiscount::query()
                ->where('club_subscription_id', $subscription->id)
                ->where('academic_year_id', $academicYear->id)
                ->active()
                ->where(function ($q) use ($startMonth, $endMonth) {
                    $q->where('start_month', '<=', $endMonth)
                      ->where('end_month', '>=', $startMonth);
                })
                ->exists();

            if ($hasActiveOverlap) {
                throw new InvalidArgumentException('يوجد تخفيض سارٍ بالفعل يتداخل مع هذه الفترة لاشتراك النادي هذا؛ ألغه أولاً قبل منح تخفيض جديد');
            }


            return ClubMonthlyDiscount::create([
                'club_subscription_id' => $subscription->id,
                'academic_year_id'     => $academicYear->id,
                'discount_type'        => $discountType,
                'monthly_amount'       => $finalAmount,
                'start_month'          => $startMonth,
                'end_month'            => $endMonth,
                'reason'               => $reason,
                'notes'                => $notes,
                'created_by'           => $createdBy,
            ]);
        });
    }

    /**
     * Cancel an active club monthly discount.
     */
    public function cancel(int $discountId, string $reason, ?int $cancelledBy = null): ClubMonthlyDiscount
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('سبب الإلغاء إجباري');
        }

        return DB::transaction(function () use ($discountId, $reason, $cancelledBy) {
            $discount = ClubMonthlyDiscount::whereKey($discountId)->lockForUpdate()->firstOrFail();

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
     * Get active club monthly discount for subscription & month.
     */
    public function getActiveDiscountForMonth(int $subscriptionId, string $month): ?ClubMonthlyDiscount
    {
        return ClubMonthlyDiscount::query()
            ->where('club_subscription_id', $subscriptionId)
            ->active()
            ->where('start_month', '<=', $month)
            ->where('end_month', '>=', $month)
            ->first();
    }
}
