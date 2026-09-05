<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\Salary;
use App\Services\EmployeeHoursService;
use App\Services\LedgerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * معالجة وحساب الرواتب في الخلفية أو بالتزامن عبر الطوابير.
 */
class ProcessSalaryCalculation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    /**
     * @param array $data بيانات الراتب أو طلب الحساب المجمّع
     * @param int|null $userId معرف المستخدم المنفّذ
     */
    public function __construct(
        public array $data,
        public ?int $userId = null
    ) {}

    /**
     * تنفيذ الوظيفة.
     */
    public function handle(LedgerService $ledger, ?EmployeeHoursService $hoursService = null): Salary|array
    {
        // إذا كان الطلب حساباً مجمعاً لساعات ورواتب الإطارات لشهر معين
        if (($this->data['mode'] ?? '') === 'batch_calculate' || !empty($this->data['calculate_all'])) {
            return $this->handleBatchCalculation($hoursService ?? app(EmployeeHoursService::class));
        }

        return $this->handleSingleSalary($ledger);
    }

    /**
     * تسجيل الراتب وخصومات التسبقات والسلف والترحيل للدفتر المركزي.
     */
    protected function handleSingleSalary(LedgerService $ledger): Salary
    {
        $gross = (float) ($this->data['gross_amount'] ?? $this->data['amount'] ?? 0);

        if ($gross <= 0) {
            throw new RuntimeException('الراتب الخام مطلوب');
        }

        $advanceIds = array_values(array_unique($this->data['advance_ids'] ?? []));

        $loanRows = collect($this->data['loan_deductions'] ?? [])
            ->map(fn (array $row) => [
                'id' => (int) $row['id'],
                'amount' => round((float) $row['amount'], 2),
            ])
            ->values();

        if ($loanRows->pluck('id')->duplicates()->isNotEmpty()) {
            throw new RuntimeException('لا يمكن خصم قسطَين من نفس السلفة في راتب واحد؛ اجمعهما في مبلغ واحد');
        }

        return DB::transaction(function () use ($ledger, $gross, $advanceIds, $loanRows) {
            $advances = collect();
            $loans = collect();
            $deduction = 0.0;

            if ($advanceIds !== []) {
                $advances = EmployeeAdvance::whereIn('id', $advanceIds)
                    ->where('employee_id', $this->data['employee_id'])
                    ->where('type', EmployeeAdvance::TYPE_ADVANCE)
                    ->whereNull('cancelled_at')
                    ->lockForUpdate()
                    ->get();

                if ($advances->count() !== count($advanceIds)) {
                    throw new RuntimeException('بعض التسبقات المختارة غير موجودة، أو ملغاة، أو لا تخصّ هذا الإطار');
                }

                foreach ($advances as $advance) {
                    if ($advance->settled_by_salary_id !== null) {
                        throw new RuntimeException('التسبقة رقم '.$advance->id.' مخصومة من راتب آخر');
                    }

                    $remaining = round((float) $advance->amount - (float) $advance->settled_amount, 2);

                    if ($remaining <= 0) {
                        throw new RuntimeException('التسبقة رقم '.$advance->id.' مخلّصة مسبقاً');
                    }

                    $deduction += $remaining;
                }
            }

            if ($loanRows->isNotEmpty()) {
                $loans = EmployeeAdvance::whereIn('id', $loanRows->pluck('id')->all())
                    ->where('employee_id', $this->data['employee_id'])
                    ->where('type', EmployeeAdvance::TYPE_LOAN)
                    ->whereNull('cancelled_at')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($loans->count() !== $loanRows->count()) {
                    throw new RuntimeException('بعض السلف المختارة غير موجودة، أو ملغاة، أو لا تخصّ هذا الإطار');
                }

                foreach ($loanRows as $row) {
                    $loan = $loans[$row['id']];
                    $repaid = (float) $loan->repayments()->whereNull('cancelled_at')->sum('amount');
                    $remaining = round((float) $loan->amount - $repaid, 2);

                    if ($remaining <= 0) {
                        throw new RuntimeException('السلفة رقم '.$loan->id.' مخلّصة بالكامل');
                    }

                    if ($row['amount'] > $remaining) {
                        throw new RuntimeException(
                            'قسط السلفة رقم '.$loan->id.' ('.number_format($row['amount'], 2, '.', '').') يتجاوز المتبقّي منها ('.number_format($remaining, 2, '.', '').')'
                        );
                    }

                    $deduction += $row['amount'];
                }
            }

            $deduction = round($deduction, 2);
            $net = round($gross - $deduction, 2);

            if ($net < 0) {
                throw new RuntimeException(
                    'مجموع الخصومات ('.number_format($deduction, 2, '.', '').') يتجاوز الراتب الخام ('.number_format($gross, 2, '.', '').')'
                );
            }

            $salary = Salary::create([
                'employee_id' => $this->data['employee_id'],
                'academic_year_id' => $this->data['academic_year_id'],
                'gross_amount' => number_format($gross, 2, '.', ''),
                'advance_deduction' => number_format($deduction, 2, '.', ''),
                'amount' => number_format($net, 2, '.', ''),
                'period_from' => $this->data['period_from'],
                'period_to' => $this->data['period_to'],
                'paid_at' => $this->data['paid_at'] ?? null,
                'method' => $this->data['method'] ?? null,
                'reference' => $this->data['reference'] ?? null,
                'notes' => $this->data['notes'] ?? null,
                'created_by' => $this->userId,
            ]);

            foreach ($advances as $advance) {
                $advance->update([
                    'settled_amount' => $advance->amount,
                    'status' => EmployeeAdvance::STATUS_SETTLED,
                    'settled_by_salary_id' => $salary->id,
                ]);
            }

            $repaidAt = $this->data['paid_at'] ?? now()->toDateString();

            foreach ($loanRows as $row) {
                $loan = $loans[$row['id']];

                EmployeeAdvanceRepayment::create([
                    'employee_advance_id' => $loan->id,
                    'employee_id' => $loan->employee_id,
                    'academic_year_id' => $loan->academic_year_id ?? $this->data['academic_year_id'],
                    'amount' => number_format($row['amount'], 2, '.', ''),
                    'repaid_at' => $repaidAt,
                    'method' => EmployeeAdvanceRepayment::METHOD_SALARY_DEDUCTION,
                    'salary_id' => $salary->id,
                    'notes' => 'قسط مخصوم ضمن الراتب رقم '.$salary->id,
                    'created_by' => $this->userId,
                ]);

                $loan->recalculateSettlement();
            }

            if ($net > 0) {
                $ledger->recordSalary($salary);
            }

            Log::info("ProcessSalaryCalculation: Successfully recorded salary #{$salary->id} for employee #{$salary->employee_id}");

            return $salary;
        });
    }

    /**
     * حساب مجمع لساعات العمل والرواتب المقترحة لجميع الإطارات لشهر محدد.
     */
    protected function handleBatchCalculation(EmployeeHoursService $hoursService): array
    {
        $year = (int) ($this->data['year'] ?? now()->year);
        $month = (int) ($this->data['month'] ?? now()->month);

        $employees = Employee::where('status', 'active')->get();
        $results = [];

        foreach ($employees as $emp) {
            $summary = $hoursService->getMonthlyHours($emp->id, $year, $month);
            $results[] = [
                'employee_id' => $emp->id,
                'name' => $emp->first_name . ' ' . $emp->last_name,
                'total_hours' => $summary['total_hours'],
                'hourly_rate' => $summary['hourly_rate'],
                'calculated_salary' => $summary['total_salary'],
            ];
        }

        Log::info("ProcessSalaryCalculation: Completed batch calculation for {$year}-{$month} across " . count($results) . " employees.");

        return $results;
    }

    public function failed(Throwable $exception): void
    {
        Log::error("ProcessSalaryCalculation failed: " . $exception->getMessage(), [
            'data' => $this->data,
            'user_id' => $this->userId,
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
