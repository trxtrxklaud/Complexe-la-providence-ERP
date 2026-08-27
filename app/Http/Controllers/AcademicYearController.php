<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Services\OpeningBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicYearController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            AcademicYear::orderByDesc('start_date')->get(['id', 'name', 'is_active', 'start_date', 'end_date', 'closed_at'])
        );
    }

    /**
     * إقفال سنة دراسية وترحيل متخلّداتها كأرصدة افتتاحية إلى السنة الجديدة.
     *
     * عملية حساسة: لا تُحذف الديون القديمة، ولا يُحوَّل قبضها مدخولاً،
     * وقيد الفرادة على (الرسم الأصلي + السنة الجديدة) يمنع الازدواج.
     */
    public function close(Request $request, AcademicYear $year): JsonResponse
    {
        $data = $request->validate([
            'target_year_id' => ['required', 'integer', 'exists:academic_years,id'],
        ]);

        try {
            $result = app(OpeningBalanceService::class)->closeYear(
                $year,
                AcademicYear::findOrFail((int) $data['target_year_id']),
                (int) $request->user()?->id
            );

            return response()->json([
                'message' => 'تم إقفال السنة الدراسية وترحيل متخلّداتها كأرصدة افتتاحية',
                'year' => ['id' => $year->id, 'name' => $year->name],
                'result' => $result,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
