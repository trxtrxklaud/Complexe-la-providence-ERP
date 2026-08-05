<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * منح تخفيض سنوي لتسجيل. سقف 20% وقاعدة الواحد السارِي يفرضهما DiscountService
 * داخل القفل؛ هنا التحقق الشكلي وحده: مبلغ موجب، سبب، وتاريخ تطبيق صحيح.
 */
class StoreDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount'       => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'reason'       => ['required', 'string', 'max:500'],
            'applied_date' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required'            => 'مبلغ التخفيض إجباري',
            'amount.numeric'             => 'مبلغ التخفيض يجب أن يكون رقماً',
            'amount.gt'                  => 'مبلغ التخفيض يجب أن يكون أكبر من صفر',
            'reason.required'            => 'سبب التخفيض إجباري',
            'reason.max'                 => 'سبب التخفيض طويل جداً (500 حرف كحدّ أقصى)',
            'applied_date.required'      => 'تاريخ تطبيق التخفيض إجباري',
            'applied_date.date'          => 'تاريخ التطبيق غير صحيح',
            'applied_date.before_or_equal' => 'لا يمكن تطبيق تخفيض بتاريخ مستقبلي',
        ];
    }
}
