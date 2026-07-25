<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSectionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $section = $this->route('section');
        $sectionId = $section?->id;
        $levelId = $this->input('level_id', $section?->level_id);

        return [
            'level_id' => 'required|exists:levels,id',
            'name' => [
                'required',
                'string',
                'max:10',
                Rule::unique('sections', 'name')
                    ->where(fn ($q) => $q->where('level_id', $levelId))
                    ->ignore($sectionId),
            ],
            'code' => ['required', 'string', 'max:20', Rule::unique('sections', 'code')->ignore($sectionId)],
            'capacity' => 'nullable|integer|min:1|max:200',
        ];
    }

    public function messages()
    {
        return [
            'name.unique' => 'يوجد قسم بهذا الاسم في نفس المستوى.',
            'code.unique' => 'رمز القسم مستعمل من قبل.',
        ];
    }

    public function attributes()
    {
        return [
            'level_id' => 'المستوى',
            'name' => 'اسم القسم',
            'code' => 'الرمز',
            'capacity' => 'السعة',
        ];
    }
}
