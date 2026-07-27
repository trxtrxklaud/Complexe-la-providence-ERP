<?php

namespace App\Http\Requests;

use App\Models\Enrollment;
use App\Models\StudentFee;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id'                   => ['required', 'integer', 'exists:students,id'],
            'enrollment_id'                => ['nullable', 'integer', 'exists:enrollments,id'],
            'amount'                       => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'payment_date'                 => ['required', 'date', 'before_or_equal:today'],
            'method'                       => ['required', 'in:cash,bank_transfer,check,card'],
            'reference'                    => ['nullable', 'string', 'max:100'],
            'notes'                        => ['nullable', 'string', 'max:500'],
            'idempotency_key'              => ['nullable', 'string', 'max:64'],
            'allocations'                  => ['nullable', 'array'],
            'allocations.*.student_fee_id' => ['required_with:allocations', 'integer', 'exists:student_fees,id'],
            'allocations.*.amount'         => ['required_with:allocations', 'numeric', 'min:0.01'],
        ];
    }

    /**
     * تحقق متقاطع بين الحقول: لا يكفي وجود التلميذ والتسجيل والرسوم كلٍّ على حدة،
     * بل يجب أن يكون التسجيل وكل رسمٍ في التوزيعات تابعاً لهذا التلميذ تحديداً.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $studentId = $this->integer('student_id');
            if (! $studentId) {
                return;
            }

            $enrollmentId = $this->integer('enrollment_id');
            if ($enrollmentId) {
                $enrollment = Enrollment::query()
                    ->select(['id', 'student_id'])
                    ->find($enrollmentId);

                if ($enrollment && (int) $enrollment->student_id !== $studentId) {
                    $validator->errors()->add('enrollment_id', 'التسجيل المحدَّد لا يخصّ هذا التلميذ');
                }
            }

            foreach ((array) $this->input('allocations', []) as $index => $allocation) {
                if (! isset($allocation['student_fee_id'])) {
                    continue;
                }

                $fee = StudentFee::with('enrollment:id,student_id')
                    ->find($allocation['student_fee_id']);

                if ($fee && (int) optional($fee->enrollment)->student_id !== $studentId) {
                    $validator->errors()->add(
                        "allocations.$index.student_fee_id",
                        'هذا الرسم لا يخصّ التلميذ المحدَّد'
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'payment_date.before_or_equal' => 'Payment date cannot be in the future.',
            'method.in'                    => 'Method must be: cash, bank_transfer, check, or card.',
        ];
    }
}
