<?php

namespace App\Http\Controllers;

use App\Models\ClubMonthlyFee;
use App\Services\ClubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ClubReportController extends Controller
{
    public function __construct(private readonly ClubService $clubService) {}

    public function arrearsDashboard(Request $request): JsonResponse
    {
        $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'club_id' => ['nullable', 'integer', 'exists:clubs,id'],
            'level_id' => ['nullable', 'integer', 'exists:levels,id'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'from_month' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'to_month' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);
        
        if ($request->filled('from_month') && $request->filled('to_month')) {
            if ($request->from_month > $request->to_month) {
                return response()->json(['message' => 'شهر البداية يجب أن يكون قبل شهر النهاية'], 422);
            }
        }

        return response()->json($this->clubService->getArrearsDashboard($request->all()));
    }

    public function report(Request $request): JsonResponse
    {
        $request->validate([
            'month' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'from_month' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'to_month' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'club_id' => ['nullable', 'integer', 'exists:clubs,id'],
            'level_id' => ['nullable', 'integer', 'exists:levels,id'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'status' => ['nullable', 'string', 'in:paid,unpaid,partial,pending,all'],
            'search' => ['nullable', 'string', 'max:100'],
            'from_month' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'to_month' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);
        
        if ($request->filled('from_month') && $request->filled('to_month')) {
            if ($request->from_month > $request->to_month) {
                return response()->json(['message' => 'شهر البداية يجب أن يكون قبل شهر النهاية'], 422);
            }
        }

        $params = $request->all();
        if (isset($params['status']) && ($params['status'] === 'all' || $params['status'] === '')) {
            $params['status'] = null;
        }

        $report = $this->clubService->getReport($params);

        return response()->json($report);
    }

    public function generateMonth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'club_id' => ['nullable', 'integer', 'exists:clubs,id'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
        ]);

        $result = $this->clubService->generateMonthFees(
            (int) $data['academic_year_id'],
            $data['month'],
            ! empty($data['club_id']) ? (int) $data['club_id'] : null,
            ! empty($data['section_id']) ? (int) $data['section_id'] : null,
            $request->user()?->id
        );

        return response()->json([
            'message' => "تم توليد سجلات الشهر بنجاح ({$result['created']} سجل جديد، {$result['skipped']} سجل موجود مسبقاً)",
            'result' => $result,
        ]);
    }

    public function collectPayment(Request $request, ClubMonthlyFee $monthlyFee): JsonResponse
    {
        $data = $request->validate([
            'amount_paid' => ['required', 'numeric', 'min:0.01'],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', 'string', 'in:cash,bank_transfer,check,card'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $updated = $this->clubService->recordPayment(
                $monthlyFee,
                (float) $data['amount_paid'],
                $data['paid_at'],
                $data['method'],
                $data['reference'] ?? null,
                $data['notes'] ?? null,
                $request->user()?->id
            );

            return response()->json([
                'message' => 'تم تسجيل استخلاص معلوم النادي بنجاح',
                'record' => $updated,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancelPayment(Request $request, ClubMonthlyFee $monthlyFee): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $cancelled = $this->clubService->cancelPayment(
                $monthlyFee,
                $request->user()?->id ?? 1,
                $data['reason']
            );

            return response()->json([
                'message' => 'تم إلغاء استخلاص معلوم النادي',
                'record' => $cancelled,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(ClubMonthlyFee $monthlyFee): JsonResponse
    {
        try {
            $this->clubService->deleteFeeRecord($monthlyFee);

            return response()->json(null, 204);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
