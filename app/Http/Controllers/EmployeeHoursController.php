<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeDailyHour;
use App\Services\EmployeeHoursService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeHoursController extends Controller
{
    public function __construct(private readonly EmployeeHoursService $service)
    {
    }

    /** الشبكة الأسبوعية لشهر: GET /employees/{employee}/hours?month=YYYY-MM */
    public function index(Employee $employee, Request $request): JsonResponse
    {
        [$year, $month] = $this->parseMonth($request->query('month'));

        return response()->json($this->service->weeklyGrid($employee->id, $year, $month));
    }

    /** تسجيل/تعديل ساعات يوم (upsert بموجب UNIQUE employee_id + work_date). */
    public function store(Employee $employee, Request $request): JsonResponse
    {
        $data = $request->validate([
            'work_date' => ['required', 'date'],
            'hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'note_type' => ['required', Rule::in(EmployeeDailyHour::NOTE_TYPES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $row = $this->service->upsert(
            $employee->id,
            $data['work_date'],
            (float) $data['hours'],
            $data['note_type'],
            $data['notes'] ?? null,
            $request->user()?->id
        );

        return response()->json($row->fresh(), 200);
    }

    /** ملخص الشهر: GET /employees/{employee}/hours/monthly-summary?month=YYYY-MM */
    public function monthlySummary(Employee $employee, Request $request): JsonResponse
    {
        [$year, $month] = $this->parseMonth($request->query('month'));

        return response()->json($this->service->getMonthlyHours($employee->id, $year, $month));
    }

    /** يحوّل YYYY-MM إلى [سنة, شهر]؛ الشهر الحالي إن غاب. */
    private function parseMonth(?string $month): array
    {
        if ($month !== null && preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [(int) now()->format('Y'), (int) now()->format('m')];
    }
}