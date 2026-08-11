<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDiscountRequest;
use App\Models\Enrollment;
use App\Models\EnrollmentDiscount;
use App\Services\DiscountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * التخفيض السنوي على معاليم التلميذ — سعر مخفّض لا دَين ولا متخلّد.
 *
 * محميّ بصلاحية waive_fees وحدها كالتنازل: كلاهما يُنقص ما تجنيه المدرسة،
 * فلا يملكه القابض. لا يُكتب سطر في الخزينة: التخفيض لا يحرّك مليماً.
 */
class DiscountController extends Controller
{
    public function __construct(private readonly DiscountService $discounts) {}

    /**
     * حالة تخفيض تسجيل: المعاليم السنوية والسقف والتخفيض السارِي وسجلّ التخفيضات.
     */
    public function show(Enrollment $enrollment): JsonResponse
    {
        $history = $enrollment->discounts()
            ->with(['creator:id,first_name,last_name', 'canceller:id,first_name,last_name'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (EnrollmentDiscount $discount) => $this->present($discount));

        return response()->json([
            'enrollment' => $this->presentEnrollment($enrollment),
            'discounts'  => $history,
        ]);
    }

    /**
     * منح تخفيض سنوي جديد. الخدمة تفرض السقف وقاعدة الواحد السارِي داخل القفل.
     */
    public function store(StoreDiscountRequest $request, Enrollment $enrollment): JsonResponse
    {
        $data = $request->validated();

        try {
            $discount = $this->discounts->createForEnrollment(
                $enrollment->id,
                (float) $data['amount'],
                $data['reason'],
                $data['applied_date'],
                $request->user()?->id
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'discount'   => $this->present($discount->fresh(['creator'])),
            'enrollment' => $this->presentEnrollment($enrollment->fresh()),
        ], 201);
    }

    /**
     * إلغاء تخفيض: يعود المستحقّ كما كان، ويبقى أثر التخفيض وإلغائه مقروءاً.
     */
    public function cancel(Request $request, EnrollmentDiscount $discount): JsonResponse
    {
        $data = $request->validate(
            [
                'reason' => ['required', 'string', 'max:500'],
            ],
            [
                'reason.required' => 'سبب الإلغاء إجباري',
                'reason.max'      => 'سبب الإلغاء طويل جداً (500 حرف كحدّ أقصى)',
            ]
        );

        try {
            $discount = $this->discounts->cancel($discount->id, $data['reason'], $request->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $enrollment = Enrollment::find($discount->enrollment_id);

        return response()->json([
            'discount'   => $this->present($discount->fresh(['creator', 'canceller'])),
            'enrollment' => $enrollment ? $this->presentEnrollment($enrollment) : null,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function present(EnrollmentDiscount $discount): array
    {
        return [
            'id'                  => $discount->id,
            'enrollment_id'       => $discount->enrollment_id,
            'academic_year_id'    => $discount->academic_year_id,
            'amount'              => (float) $discount->amount,
            'percentage'          => $discount->percentage !== null ? (float) $discount->percentage : null,
            'reason'              => $discount->reason,
            'applied_date'        => $discount->applied_date?->format('Y-m-d'),
            'created_at'          => $discount->created_at?->toIso8601String(),
            'created_by'          => $discount->creator
                ? trim($discount->creator->first_name . ' ' . $discount->creator->last_name)
                : null,
            'cancelled_at'        => $discount->cancelled_at?->toIso8601String(),
            'cancelled_by'        => $discount->canceller
                ? trim($discount->canceller->first_name . ' ' . $discount->canceller->last_name)
                : null,
            'cancellation_reason' => $discount->cancellation_reason,
            'is_cancelled'        => $discount->isCancelled(),
        ];
    }

    /**
     * ملخّص تخفيض التسجيل: المعاليم السنوية من المخطّط، السقف، التخفيض السارِي،
     * والصافي بعده. تقرؤه الواجهة لتعرض «المعاليم قبل/بعد التخفيض» دون حساب محلّي.
     *
     * @return array<string,mixed>
     */
    private function presentEnrollment(Enrollment $enrollment): array
    {
        $annualFees      = $this->discounts->calculateAnnualFees($enrollment);
        $cap             = $this->discounts->capForEnrollment($enrollment);
        $activeDiscount  = $this->discounts->getTotalForEnrollment(
            $enrollment->id,
            (int) $enrollment->academic_year_id
        );

        return [
            'id'               => $enrollment->id,
            'academic_year_id' => (int) $enrollment->academic_year_id,
            'annual_fees'      => $annualFees,
            'discount_cap'     => $cap,
            'active_discount'  => $activeDiscount,
            'net_fees'         => round($annualFees - $activeDiscount, 2),
        ];
    }
}
