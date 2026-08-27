<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeDailyHour;
use Illuminate\Support\Carbon;

/**
 * ساعات العمل اليومية للمعلم الساعي وحساب راتبه الشهري.
 *
 * القاعدة المحاسبية:
 * - الصفّ العادي/التعويض/الإضافي يضيف ساعاته.
 * - صفّ الغياب (note_type = absence) يخزّن ساعات موجبة تُطرَح من المجموع
 *   منطقياً، فلا توجد ساعات سالبة في الجدول إطلاقاً.
 * - الراتب = (مجموع ساعات الشهر − ساعات الغياب) × معلوم الساعة، ولا يقل عن صفر.
 * - هذه الخدمة تحسب وتقترح فحسب: لا تُنشئ راتباً ولا صفّاً في الدفتر النقدي —
 *   الخلاص يدوي عبر SalaryController.
 */
class EmployeeHoursService
{
    /** أيام العمل: الاثنين → السبت. */
    public const WORK_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    /**
     * ملخص شهر كامل: مجموع الساعات، الراتب المحتسب، وعدّادا العمل والغياب.
     *
     * @return array{total_hours: float, total_salary: float, hourly_rate: float,
     *               work_days: int, absence_days: int, entries: int}
     */
    public function getMonthlyHours(int $employeeId, int $year, int $month): array
    {
        $employee = Employee::findOrFail($employeeId);
        $hourlyRate = (float) ($employee->hourly_rate ?? 0);

        $rows = EmployeeDailyHour::where('employee_id', $employeeId)
            ->whereYear('work_date', $year)
            ->whereMonth('work_date', $month)
            ->get();

        $workedHours = 0.0;
        $absenceHours = 0.0;
        $workDays = 0;
        $absenceDays = 0;

        foreach ($rows as $row) {
            $h = (float) $row->hours;
            if ($row->note_type === 'absence') {
                $absenceHours += $h;
                $absenceDays++;
            } else {
                $workedHours += $h;
                if ($h > 0) {
                    $workDays++;
                }
            }
        }

        $totalHours = max(0.0, round($workedHours - $absenceHours, 2));

        return [
            'total_hours' => $totalHours,
            'total_salary' => round($totalHours * $hourlyRate, 2),
            'hourly_rate' => $hourlyRate,
            'work_days' => $workDays,
            'absence_days' => $absenceDays,
            'entries' => $rows->count(),
        ];
    }

    /**
     * شبكة أسبوعية لشهر كامل: أسابيع الاثنين → السبت، أيام الشهر فقط،
     * مع إجمالي ساعات كل أسبوع (بالقاعدة نفسها: العادي + التعويض + الإضافي − الغياب).
     */
    public function weeklyGrid(int $employeeId, int $year, int $month): array
    {
        $rows = EmployeeDailyHour::where('employee_id', $employeeId)
            ->whereYear('work_date', $year)
            ->whereMonth('work_date', $month)
            ->get()
            ->keyBy(fn ($row) => $row->work_date->format('Y-m-d'));

        $first = Carbon::create($year, $month, 1)->startOfDay();
        $last = $first->copy()->endOfMonth();
        $monthEnd = $last->format('Y-m-d');

        // الاثنين الذي يسبق أو يوافق أول الشهر.
        $weekStart = $first->copy()->startOfWeek(Carbon::MONDAY);
        if ($weekStart->lt($first)) {
            $weekStart = $weekStart->copy()->addWeek();
        }

        $weeks = [];

        while ($weekStart->format('Y-m-d') <= $monthEnd) {
            $days = [];
            $weeklyHours = 0.0;

            foreach (self::WORK_DAYS as $i => $dayKey) {
                $date = $weekStart->copy()->addDays($i);
                $dateStr = $date->format('Y-m-d');
                $inMonth = $date->between($first, $last);

                if (! $inMonth) {
                    $days[] = [
                        'date' => $dateStr,
                        'in_month' => false,
                        'hours' => 0,
                        'note_type' => 'normal',
                        'notes' => null,
                        'id' => null,
                    ];
                    continue;
                }

                $row = $rows->get($dateStr);
                $hours = $row ? (float) $row->hours : 0.0;
                $noteType = $row ? $row->note_type : 'normal';

                // الأسبوع يجمع كل الساعات المسجّلة (الطرح يصير فقط في getMonthlyHours)
                $weeklyHours += $hours;

                $days[] = [
                    'date' => $dateStr,
                    'in_month' => true,
                    'hours' => $hours,
                    'note_type' => $noteType,
                    'notes' => $row?->notes,
                    'id' => $row?->id,
                ];
            }

            $weeks[] = [
                'week_start' => $weekStart->format('Y-m-d'),
                'weekly_hours' => round(max(0.0, $weeklyHours), 2),
                'days' => $days,
            ];

            $weekStart = $weekStart->copy()->addWeek();
        }

        return [
            'year' => $year,
            'month' => $month,
            'weeks' => $weeks,
            'summary' => $this->getMonthlyHours($employeeId, $year, $month),
        ];
    }

    /**
     * تسجيل/تعديل ساعات يوم واحد — استبدال (upsert) بموجب
     * UNIQUE(employee_id, work_date).
     */
    public function upsert(
        int $employeeId,
        string $workDate,
        float $hours,
        string $noteType,
        ?string $notes = null,
        ?int $createdBy = null
    ): EmployeeDailyHour {
        if (! in_array($noteType, EmployeeDailyHour::NOTE_TYPES, true)) {
            throw new \InvalidArgumentException('نوع الإدخال غير صالح');
        }

        $hours = round(max(0.0, $hours), 2);

        return EmployeeDailyHour::updateOrCreate(
            ['employee_id' => $employeeId, 'work_date' => $workDate],
            [
                'hours' => $hours,
                'note_type' => $noteType,
                'notes' => $notes !== null && $notes !== '' ? trim($notes) : null,
                'created_by' => $createdBy,
            ]
        );
    }
}