<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TransferStudentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'source_section_id' => ['required', 'integer', 'exists:sections,id'],
            'destination_section_id' => ['required', 'integer', 'exists:sections,id'],
            'student_ids' => ['required', 'array', 'min:1', 'max:200'],
            'student_ids.*' => ['required', 'integer', 'distinct', 'exists:students,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (
                $this->filled('source_section_id')
                && $this->filled('destination_section_id')
                && $this->integer('source_section_id') === $this->integer('destination_section_id')
            ) {
                $validator->errors()->add(
                    'destination_section_id',
                    'يجب اختيار قسم وجهة مختلف عن القسم المصدر.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'student_ids.required' => 'اختر تلميذًا واحدًا على الأقل.',
            'student_ids.min' => 'اختر تلميذًا واحدًا على الأقل.',
            'student_ids.max' => 'لا يمكن نقل أكثر من 200 تلميذ دفعة واحدة.',
            'student_ids.*.distinct' => 'قائمة التلاميذ المحددة تحتوي على تكرار.',
        ];
    }
}
