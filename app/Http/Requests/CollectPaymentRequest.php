<?php

namespace App\Http\Requests;

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
            'months'               => ['required', 'array', 'min:1'],
            'months.*'             => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'payment_date'         => ['required', 'date', 'before_or_equal:today'],
            'method'               => ['required', 'in:cash,bank_transfer,check,card'],
            'reference'            => ['nullable', 'string', 'max:100'],
            'notes'                => ['nullable', 'string', 'max:500'],
            'discount'             => ['nullable', 'numeric', 'min:0'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.fee_type_id'  => ['required', 'integer', 'exists:fee_types,id'],
            'items.*.amount'       => ['required', 'numeric', 'min:0.01'],
        ];
    }

    public function messages(): array
    {
        return [
            'student_id.required'        => 'يجب تحديد التلميذ',
            'enrollment_id.required'     => 'يجب تحديد التسجيل',
            'months.required'            => 'يجب تحديد الشهر المدفوع',
            'months.min'                 => 'يجب اختيار شهر واحد على الأقل',
            'months.*.regex'             => 'صيغة الشهر يجب أن تكون YYYY-MM',
            'payment_date.required'      => 'تاريخ الدفع مطلوب',
            'method.required'            => 'طريقة الدفع مطلوبة',
            'method.in'                  => 'طريقة الدفع غير صحيحة',
            'discount.min'               => 'التخفيض لا يمكن أن يكون سالباً',
            'items.required'             => 'يجب اختيار رسم واحد على الأقل',
            'items.*.fee_type_id.exists' => 'نوع الرسم غير موجود',
            'items.*.amount.min'         => 'يجب أن يكون المبلغ أكبر من صفر',
        ];
    }
}
