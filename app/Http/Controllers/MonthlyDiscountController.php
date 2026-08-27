<?php

namespace App\Http\Controllers;


use App\Models\Enrollment;
use App\Models\MonthlyDiscount;
use App\Services\MonthlyDiscountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class MonthlyDiscountController extends Controller
{
    public function __construct(private readonly MonthlyDiscountService $service) {}

    public function index(Enrollment $enrollment): JsonResponse
    {
        $discounts = $enrollment->monthlyDiscounts()
            ->with(['creator:id,first_name,last_name', 'canceller:id,first_name,last_name'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (MonthlyDiscount $d) => $this->present($d));

        return response()->json([
            'enrollment_id' => $enrollment->id,
            'discounts'     => $discounts,
        ]);
    }

    public function store(Request $request, Enrollment $enrollment): JsonResponse
    {
        $data = $request->validate([
            'discount_type'  => ['required', 'string', 'in:full_waiver,humanitarian_fixed,normal_monthly'],
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
                $enrollment->id,
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
            'message'  => 'تم تسجيل التخفيض الشهري بنجاح',
            'discount' => $this->present($discount->fresh(['creator'])),
        ], 201);
    }

    public function cancel(Request $request, MonthlyDiscount $discount): JsonResponse
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
            'message'  => 'تم إلغاء التخفيض الشهري',
            'discount' => $this->present($cancelled->fresh(['creator', 'canceller'])),
        ]);
    }

    private function present(MonthlyDiscount $d): array
    {
        return [
            'id'                  => $d->id,
            'enrollment_id'       => $d->enrollment_id,
            'academic_year_id'    => $d->academic_year_id,
            'discount_type'       => $d->discount_type,
            'monthly_amount'      => $d->monthly_amount !== null ? (float) $d->monthly_amount : null,
            'fee_category'        => $d->fee_category,
            'start_month'         => $d->start_month,
            'end_month'           => $d->end_month,
            'reason'              => $d->reason,
            'notes'               => $d->notes,
            'created_at'          => $d->created_at?->toIso8601String(),
            'created_by'          => $d->creator ? trim($d->creator->first_name . ' ' . $d->creator->last_name) : null,
            'cancelled_at'        => $d->cancelled_at?->toIso8601String(),
            'cancelled_by'        => $d->canceller ? trim($d->canceller->first_name . ' ' . $d->canceller->last_name) : null,
            'cancellation_reason' => $d->cancellation_reason,
            'is_cancelled'        => $d->isCancelled(),
        ];
    }
}
