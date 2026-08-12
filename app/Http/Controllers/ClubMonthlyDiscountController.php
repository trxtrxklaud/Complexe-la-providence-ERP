<?php

namespace App\Http\Controllers;

use App\Models\ClubMonthlyDiscount;
use App\Models\ClubSubscription;
use App\Services\ClubMonthlyDiscountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ClubMonthlyDiscountController extends Controller
{
    public function __construct(private readonly ClubMonthlyDiscountService $service) {}

    public function index(ClubSubscription $subscription): JsonResponse
    {
        $discounts = $subscription->monthlyDiscounts()
            ->with(['creator:id,first_name,last_name', 'canceller:id,first_name,last_name'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (ClubMonthlyDiscount $d) => $this->present($d));

        return response()->json([
            'club_subscription_id' => $subscription->id,
            'discounts'            => $discounts,
        ]);
    }

    public function store(Request $request, ClubSubscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'discount_type'  => ['required', 'string', 'in:full_waiver,humanitarian_fixed'],
            'monthly_amount' => ['nullable', 'numeric', 'min:0'],
            'reason'         => ['required', 'string', 'max:500'],
            'notes'          => ['nullable', 'string', 'max:1000'],
        ], [
            'discount_type.required' => 'نوع التخفيض إجباري',
            'discount_type.in'       => 'نوع التخفيض غير صحيح',
            'reason.required'        => 'سبب التخفيض إجباري',
            'reason.max'             => 'سبب التخفيض طويل جداً',
        ]);

        try {
            $discount = $this->service->createDiscount(
                $subscription->id,
                $data['discount_type'],
                isset($data['monthly_amount']) ? (float) $data['monthly_amount'] : null,
                $data['reason'],
                $data['notes'] ?? null,
                $request->user()?->id
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message'  => 'تم تسجيل تخفيض النادي بنجاح',
            'discount' => $this->present($discount->fresh(['creator'])),
        ], 201);
    }

    public function cancel(Request $request, ClubMonthlyDiscount $discount): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'سبب الإلغاء إجباري',
        ]);

        try {
            $cancelled = $this->service->cancel($discount->id, $data['reason'], $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message'  => 'تم إلغاء تخفيض النادي',
            'discount' => $this->present($cancelled->fresh(['creator', 'canceller'])),
        ]);
    }

    private function present(ClubMonthlyDiscount $d): array
    {
        return [
            'id'                   => $d->id,
            'club_subscription_id' => $d->club_subscription_id,
            'academic_year_id'     => $d->academic_year_id,
            'discount_type'        => $d->discount_type,
            'monthly_amount'       => $d->monthly_amount !== null ? (float) $d->monthly_amount : null,
            'start_month'          => $d->start_month,
            'end_month'            => $d->end_month,
            'reason'               => $d->reason,
            'notes'                => $d->notes,
            'created_at'           => $d->created_at?->toIso8601String(),
            'created_by'           => $d->creator ? trim($d->creator->first_name . ' ' . $d->creator->last_name) : null,
            'cancelled_at'         => $d->cancelled_at?->toIso8601String(),
            'cancelled_by'         => $d->canceller ? trim($d->canceller->first_name . ' ' . $d->canceller->last_name) : null,
            'cancellation_reason'  => $d->cancellation_reason,
            'is_cancelled'         => $d->isCancelled(),
        ];
    }
}
