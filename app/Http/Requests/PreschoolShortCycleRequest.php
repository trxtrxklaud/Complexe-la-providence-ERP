<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreschoolShortCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enrollment_id'   => ['required', 'integer', 'exists:enrollments,id'],
            'payment_date'    => ['required', 'date', 'before_or_equal:today'],
            'method'          => ['required', 'string', 'in:cash,bank_transfer,check,card'],
            'reference'       => ['nullable', 'string', 'max:100'],
            'notes'           => ['nullable', 'string', 'max:500'],
            'cycle_mode'      => ['required', 'string', 'in:half_rate,full_rate'],
            'half_rate_month' => ['nullable', 'required_if:cycle_mode,half_rate', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'enrollment_id.required'      => 'معرّف التسجيل مطلوب.',
            'enrollment_id.exists'        => 'التسجيل المحدد غير موجود.',
            'payment_date.required'       => 'تاريخ الدفع مطلوب.',
            'payment_date.date'           => 'تاريخ الدفع غير صالح.',
            'method.required'             => 'طريقة الدفع مطلوبة.',
            'method.in'                   => 'طريقة الدفع غير صالحة.',
            'cycle_mode.required'         => 'وضع الدورة مطلوب.',
            'cycle_mode.in'               => 'وضع الدورة يجب أن يكون half_rate أو full_rate.',
            'half_rate_month.required_if' => 'الشهر مطلوب عند اختيار نصف المعلوم.',
            'half_rate_month.regex'       => 'صيغة الشهر يجب أن تكون YYYY-MM.',
        ];
    }
}