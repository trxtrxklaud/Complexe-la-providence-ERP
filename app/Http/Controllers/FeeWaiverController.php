<?php

namespace App\Http\Controllers;

use App\Models\FeeWaiver;
use App\Models\StudentFee;
use App\Services\FeeWaiverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * التنازل عن متبقّي رسم التلميذ.
 *
 * محميّ بصلاحية waive_fees وحدها، وليس بـ manage_payments، حتّى لا يملك
 * القابض إسقاط دُيون الأولياء.
 */
class FeeWaiverController extends Controller
{
    public function __construct(private readonly FeeWaiverService $waivers) {}

    public function index(StudentFee $studentFee): JsonResponse
    {
        $waivers = $studentFee->waivers()
            ->with(['creator:id,first_name,last_name', 'canceller:id,first_name,last_name'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (FeeWaiver $waiver) => $this->present($waiver));

        return response()->json([
            'fee'     => $this->presentFee($studentFee),
            'waivers' => $waivers,
        ]);
    }

    public function store(Request $request, StudentFee $studentFee): JsonResponse
    {
        $data = $request->validate(
            [
                'amount' => ['required', 'numeric', 'gt:0'],
                'reason' => ['required', 'string', 'max:500'],
            ],
            [
                'amount.required' => 'مبلغ التنازل إجباري',
                'amount.numeric'  => 'مبلغ التنازل يجب أن يكون رقماً',
                'amount.gt'       => 'مبلغ التنازل يجب أن يكون أكبر من صفر',
                'reason.required' => 'سبب التنازل إجباري',
                'reason.max'      => 'سبب التنازل طويل جداً (500 حرف كحدّ أقصى)',
            ]
        );

        try {
            $waiver = $this->waivers->waive(
                $studentFee,
                (float) $data['amount'],
                $data['reason'],
                $request->user()?->id
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'waiver' => $this->present($waiver->fresh(['creator'])),
            'fee'    => $this->presentFee($studentFee->fresh()),
        ], 201);
    }

    public function cancel(Request $request, FeeWaiver $waiver): JsonResponse
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
            $waiver = $this->waivers->cancel($waiver, $data['reason'], $request->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $fee = StudentFee::find($waiver->student_fee_id);

        return response()->json([
            'waiver' => $this->present($waiver->fresh(['creator', 'canceller'])),
            'fee'    => $fee ? $this->presentFee($fee) : null,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function present(FeeWaiver $waiver): array
    {
        return [
            'id'                  => $waiver->id,
            'student_fee_id'      => $waiver->student_fee_id,
            'amount'              => (float) $waiver->amount,
            'reason'              => $waiver->reason,
            'created_at'          => $waiver->created_at?->toIso8601String(),
            'created_by'          => $waiver->creator
                ? trim($waiver->creator->first_name . ' ' . $waiver->creator->last_name)
                : null,
            'cancelled_at'        => $waiver->cancelled_at?->toIso8601String(),
            'cancelled_by'        => $waiver->canceller
                ? trim($waiver->canceller->first_name . ' ' . $waiver->canceller->last_name)
                : null,
            'cancellation_reason' => $waiver->cancellation_reason,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function presentFee(StudentFee $fee): array
    {
        return [
            'id'          => $fee->id,
            'description' => $fee->description,
            'amount_due'  => (float) $fee->amount_due,
            'allocated'   => $fee->allocatedAmount(),
            'waived'      => $fee->waivedAmount(),
            'outstanding' => $fee->outstanding(),
            'status'      => $fee->status,
        ];
    }
}
