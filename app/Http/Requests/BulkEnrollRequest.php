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
            'students' => ['required', 'array', 'min:1', 'max:200'],
            'students.*.first_name' => ['required', 'string', 'min:2', 'max:120'],
            'students.*.last_name' => ['required', 'string', 'min:2', 'max:120'],
            'students.*.father_name' => ['nullable', 'string', 'max:120'],
            'students.*.mother_name' => ['nullable', 'string', 'max:120'],
            'students.*.father_phone' => ['nullable', 'string', 'max:20'],
            'students.*.mother_phone' => ['nullable', 'string', 'max:20'],
            'async' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'academic_year_id' => 'السنة الدراسية',
            'section_id' => 'القسم',
            'students' => 'قائمة التلاميذ',
            'students.*.first_name' => 'الاسم',
            'students.*.last_name' => 'اللقب',
        ];
    }

    public function messages(): array
    {
        return [
            'students.required' => 'أضف تلميذاً واحداً على الأقل.',
            'students.max' => 'لا يمكن تسجيل أكثر من 200 تلميذ دفعةً واحدة.',
            'students.*.first_name.required' => 'الاسم إجباري.',
            'students.*.first_name.min' => 'الاسم قصير جداً.',
            'students.*.last_name.required' => 'اللقب إجباري.',
            'students.*.last_name.min' => 'اللقب قصير جداً.',
        ];
    }
}
