<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkEnrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'section_id' => ['required', 'integer', 'exists:sections,id'],
            'names' => ['required', 'array', 'min:1', 'max:200'],
            'names.*' => ['required', 'string', 'min:2', 'max:120'],
        ];
    }

    public function attributes(): array
    {
        return [
            'academic_year_id' => 'السنة الدراسية',
            'section_id' => 'القسم',
            'names' => 'قائمة الأسماء',
        ];
    }

    public function messages(): array
    {
        return [
            'names.required' => 'اكتب اسماً واحداً على الأقل.',
            'names.max' => 'لا يمكن تسجيل أكثر من 200 تلميذ دفعةً واحدة.',
            'names.*.min' => 'أحد الأسماء قصير جداً.',
        ];
    }
}
