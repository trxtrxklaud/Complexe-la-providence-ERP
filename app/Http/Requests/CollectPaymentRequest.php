<?php

namespace App\Http\Requests;

use App\Models\Enrollment;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class CollectPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'enrollment_id' => ['required', 'integer', 'exists:enrollments,id'],
            'months' => ['nullable', 'array', 'min:1', 'max:12', 'required_without:prior_allocations'],
            'months.*' => ['required', 'string', 'distinct', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', 'in:cash,bank_transfer,check,card'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'items' => ['nullable', 'array', 'min:1', 'max:20', 'required_without:prior_allocations'],
            'items.*.fee_type_id' => ['required', 'integer', 'distinct', 'exists:fee_types,id'],
            'items.*.amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'club_items' => ['nullable', 'array', 'max:50'],
            'club_items.*.club_monthly_fee_id' => ['required', 'integer', 'distinct', 'exists:club_monthly_fees,id'],
            'club_items.*.amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],

            // توزيع صريح على متخلّدات السنوات السابقة (اختياري):
            // رسم سابق، أو رصيد افتتاحي، أو دَين قديم مُدخل يدوياً.
            'prior_allocations' => ['nullable', 'array', 'max:50'],
            'prior_allocations.*.student_fee_id' => ['nullable', 'integer', 'distinct', 'exists:student_fees,id', 'required_without_all:prior_allocations.*.opening_balance_id,prior_allocations.*.manual_student_debt_id'],
            'prior_allocations.*.opening_balance_id' => ['nullable', 'integer', 'distinct', 'exists:opening_balances,id', 'required_without_all:prior_allocations.*.student_fee_id,prior_allocations.*.manual_student_debt_id'],
            'prior_allocations.*.manual_student_debt_id' => ['nullable', 'integer', 'distinct', 'exists:manual_student_debts,id', 'required_without_all:prior_allocations.*.student_fee_id,prior_allocations.*.opening_balance_id'],
            'prior_allocations.*.amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
        ];
    }

    /**
     * تحقق متقاطع بين الحقول — لا يكفي وجود التلميذ والتسجيل كلٍّ على حدة؛
     * يجب أن يكون التسجيل تابعاً لذلك التلميذ تحديداً.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $studentId = $this->integer('student_id');
            $enrollmentId = $this->integer('enrollment_id');

            if (! $studentId || ! $enrollmentId) {
                return;
            }

            $enrollment = Enrollment::query()
                ->select(['id', 'student_id', 'status'])
                ->find($enrollmentId);

            if (! $enrollment) {
                return;
            }

            if ((int) $enrollment->student_id !== $studentId) {
                $validator->errors()->add(
                    'enrollment_id',
                    'التسجيل المحدَّد لا يخصّ هذا التلميذ'
                );
            }

            foreach ((array) $this->input('prior_allocations', []) as $index => $allocation) {
                $hasFee = ! empty($allocation['student_fee_id']);
                $hasOpeningBalance = ! empty($allocation['opening_balance_id']);
                $hasManualDebt = ! empty($allocation['manual_student_debt_id']);
                $targets = array_sum([$hasFee, $hasOpeningBalance, $hasManualDebt]);

                if ($targets !== 1) {
                    $validator->errors()->add(
                        "prior_allocations.$index",
                        'يجب تحديد student_fee_id أو opening_balance_id أو manual_student_debt_id فقط'
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'student_id.required' => 'يجب تحديد التلميذ',
            'enrollment_id.required' => 'يجب تحديد التسجيل',
            'months.required' => 'يجب تحديد الشهر المدفوع',
            'months.min' => 'يجب اختيار شهر واحد على الأقل',
            'months.*.regex' => 'صيغة الشهر يجب أن تكون YYYY-MM',
            'months.*.distinct' => 'لا يمكن تكرار نفس الشهر',
            'payment_date.required' => 'تاريخ الدفع مطلوب',
            'payment_date.before_or_equal' => 'لا يمكن تسجيل دفعة بتاريخ مستقبلي',
            'method.required' => 'طريقة الدفع مطلوبة',
            'method.in' => 'طريقة الدفع غير صحيحة',
            'items.required' => 'يجب اختيار رسم واحد على الأقل',
            'items.*.fee_type_id.exists' => 'نوع الرسم غير موجود',
            'items.*.fee_type_id.distinct' => 'لا يمكن تكرار نفس نوع الرسم مرتين',
            'items.*.amount.min' => 'يجب أن يكون المبلغ أكبر من صفر',
            'club_items.*.amount.min' => 'يجب أن يكون مبلغ النادي أكبر من صفر',
        ];
    }
}
