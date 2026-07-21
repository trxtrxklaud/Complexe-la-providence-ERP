<?php

namespace App\Http\Requests;

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
            'allocations'                  => ['nullable', 'array'],
            'allocations.*.student_fee_id' => ['required_with:allocations', 'integer', 'exists:student_fees,id'],
            'allocations.*.amount'         => ['required_with:allocations', 'numeric', 'min:0.01'],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_date.before_or_equal' => 'Payment date cannot be in the future.',
            'method.in'                    => 'Method must be: cash, bank_transfer, check, or card.',
        ];
    }
}
