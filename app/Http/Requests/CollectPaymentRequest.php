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
            'student_id'           => ['required', 'integer', 'exists:students,id'],
            'enrollment_id'        => ['required', 'integer', 'exists:enrollments,id'],
            'months'               => ['required', 'array', 'min:1', 'max:12'],
            'months.*'             => ['required', 'string', 'distinct', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'payment_date'         => ['required', 'date', 'before_or_equal:today'],
            'method'               => ['required', 'in:cash,bank_transfer,check,card'],
            'reference'            => ['nullable', 'string', 'max:100'],
            'notes'                => ['nullable', 'string', 'max:500'],
            'idempotency_key'      => ['nullable', 'string', 'max:64'],
            'discount'             => ['nullable', 'numeric', 'min:0'],
            'items'                => ['required', 'array', 'min:1', 'max:20'],
            'items.*.fee_type_id'  => ['required', 'integer', 'distinct', 'exists:fee_types,id'],
            'items.*.amount'       => ['required', 'numeric', 'min:0.01', 'max:1000000'],
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
        });
    }

    public function messages(): array
    {
        return [
            'student_id.required'        => 'يجب تحديد التلميذ',
            'enrollment_id.required'     => 'يجب تحديد التسجيل',
            'months.required'            => 'يجب تحديد الشهر المدفوع',
            'months.min'                 => 'يجب اختيار شهر واحد على الأقل',
            'months.*.regex'             => 'صيغة الشهر يجب أن تكون YYYY-MM',
            'months.*.distinct'          => 'لا يمكن تكرار نفس الشهر',
            'payment_date.required'      => 'تاريخ الدفع مطلوب',
            'payment_date.before_or_equal' => 'لا يمكن تسجيل دفعة بتاريخ مستقبلي',
            'method.required'            => 'طريقة الدفع مطلوبة',
            'method.in'                  => 'طريقة الدفع غير صحيحة',
            'discount.min'               => 'التخفيض لا يمكن أن يكون سالباً',
            'items.required'             => 'يجب اختيار رسم واحد على الأقل',
            'items.*.fee_type_id.exists' => 'نوع الرسم غير موجود',
            'items.*.fee_type_id.distinct' => 'لا يمكن تكرار نفس نوع الرسم مرتين',
            'items.*.amount.min'         => 'يجب أن يكون المبلغ أكبر من صفر',
        ];
    }
}
