<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name'           => 'required|string|max:255',
            'last_name'            => 'required|string|max:255',
            'dob'                  => 'required|date',
            'gender'               => 'required|in:male,female',
            'notes'                => 'nullable|string|max:1000',
            'guardian_first_name'  => 'required|string|max:255',
            'guardian_last_name'   => 'required|string|max:255',
            'guardian_phone'       => 'required|string|max:20',
            'guardian_email'       => 'nullable|email|max:255',
            'address'              => 'required|string|max:1000',
            'mother_phone'         => 'nullable|string|max:20',
            'mother_email'         => 'nullable|email|max:255',
            'level_id'             => 'required|exists:levels,id',
            'section_name'         => 'nullable|string|in:أ,ب,ج,د,هـ',
            'photo'                => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
        ];
    }

    public function messages(): array
    {
        return [
            'guardian_phone.required' => 'رقم هاتف الولي مطلوب',
            'address.required'        => 'عنوان الولي مطلوب',
        ];
    }
}
