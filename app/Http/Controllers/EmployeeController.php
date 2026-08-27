<?php

namespace App\Http\Controllers;

use App\Models\CashTransaction;
use App\Models\Employee;
use App\Models\Salary;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\OldEmployeeDebt;
use App\Models\OldEmployeeDebtCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(): JsonResponse
    {
        $items = Employee::orderBy('last_name')->orderBy('first_name')->get();
        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $data['is_active'] = $data['is_active'] ?? true;
        $data['staff_type'] = $data['staff_type'] ?? 'monthly_teacher';
        $data['salary_type'] = $data['salary_type'] ?? 'monthly';

        // backfill (monthly_salary من default_salary) يقوم به نموذج Employee عند الإنشاء.
        $emp = Employee::create($data);
        return response()->json($emp, 201);
    }

    public function show(Employee $employee): JsonResponse
    {
        return response()->json($employee);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $employee->update($data);
        return response()->json($employee->fresh());
    }

    private function rules(bool $partial = false): array
    {
        $prefix = $partial ? 'sometimes' : 'required';

        return [
            'first_name' => [$prefix, 'string', 'max:100'],
            'last_name' => [$prefix, 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'staff_type' => ['nullable', Rule::in(array_keys(Employee::STAFF_TYPES))],
            'salary_type' => ['nullable', Rule::in(array_keys(Employee::SALARY_TYPES))],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'monthly_salary' => ['nullable', 'numeric', 'min:0'],
            'default_salary' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function destroy(Employee $employee): JsonResponse
    {
        // عدّادات الحماية — لا حذف تلقائي ولا cascade، فقط منع وشرح
        $salariesCount = $employee->salaries()->count();
        $advancesCount = $employee->advances()->count();
        $repaymentsCount = $employee->repayments()->count();
        $dailyHoursCount = $employee->dailyHours()->count();
        $openingDebtsCount = $employee->openingDebts()->count();

        // cash_transactions المرتبطة عبر morph (source_type / source_id)
        // المصدر يُخزن عبر getMorphClass() في LedgerService::post()
        $salaryIds = $employee->salaries()->pluck('id')->all();
        $advanceIds = $employee->advances()->pluck('id')->all();
        $repaymentIds = $employee->repayments()->pluck('id')->all();
        $openingDebtIds = $employee->openingDebts()->pluck('id')->all();
        $collectionIds = ! empty($openingDebtIds)
            ? OldEmployeeDebtCollection::whereIn('employee_opening_debt_id', $openingDebtIds)->pluck('id')->all()
            : [];

        $cashCount = 0;
        $morphChecks = [
            [$salaryIds, (new Salary())->getMorphClass()],
            [$advanceIds, (new EmployeeAdvance())->getMorphClass()],
            [$repaymentIds, (new EmployeeAdvanceRepayment())->getMorphClass()],
            [$openingDebtIds, (new OldEmployeeDebt())->getMorphClass()],
            [$collectionIds, (new OldEmployeeDebtCollection())->getMorphClass()],
        ];
        foreach ($morphChecks as [$ids, $morph]) {
            if (! empty($ids)) {
                $cashCount += CashTransaction::where('source_type', $morph)->whereIn('source_id', $ids)->count();
            }
        }

        $details = [
            'salaries' => $salariesCount,
            'advances' => $advancesCount,
            'repayments' => $repaymentsCount,
            'daily_hours' => $dailyHoursCount,
            'opening_debts' => $openingDebtsCount,
            'cash_transactions' => $cashCount,
        ];

        $hasRelatedRecords = $salariesCount > 0
            || $advancesCount > 0
            || $repaymentsCount > 0
            || $dailyHoursCount > 0
            || $openingDebtsCount > 0
            || $cashCount > 0;

        if ($hasRelatedRecords) {
            return response()->json([
                'message' => 'لا يمكن حذف هذا الإطار لأنه مرتبط بسجلات مالية أو رواتب أو سلف.',
                'details' => $details,
            ], 422);
        }

        $employee->delete();
        return response()->json(['message' => 'تم الحذف']);
    }
}
