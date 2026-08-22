<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * قواعد إدخال استحقاق إطار قديم.
 *
 * نوع الاستحقاق يتبع تصنيف الإطار (employees.staff_type):
 *   عاملة  worker                      → دين فقط
 *   معلم   hourly/monthly_teacher      → دين + سلفة غير مسددة
 *   منشط   club_animator               → دين + سلفة غير مسددة
 *   أي تصنيف آخر (مدير، قيم…)          → دين فقط (الاحتياطية)
 *
 * القاعدة نفسها معروضة في الواجهة (manualDebts.ts) حتّى لا يعرض النموذج
 * خيارات لا يقبلها الخادم.
 */
class StoreEmployeeLiabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'original_year_label' => ['required', 'string', 'max:20'],
            'liability_type' => ['required', 'string', Rule::in(['debt', 'advance'])],
            'description' => ['required', 'string', 'max:255'],
            'original_amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['employee_id', 'liability_type'])) {
                return;
            }

            $employee = Employee::query()
                ->select(['id', 'staff_type'])
                ->find($this->integer('employee_id'));

            if (! $employee) {
                return;
            }

            $allowedTypes = match ((string) $employee->staff_type) {
                'worker' => ['debt'],
                'hourly_teacher', 'monthly_teacher', 'club_animator' => ['debt', 'advance'],
                default => ['debt'],
            };

            if (! in_array((string) $this->input('liability_type'), $allowedTypes, true)) {
                $validator->errors()->add('liability_type', 'نوع الالتزام غير مسموح لهذا الإطار.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'liability_type.in' => 'نوع الالتزام يجب أن يكون دَيناً أو سلفة غير مسددة.',
        ];
    }
}
