<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLevelRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $level = $this->route('level');
        $levelId = $level?->id;

        return [
            'name' => 'required|string|max:255',
            'code' => ['required', 'string', 'max:20', Rule::unique('levels', 'code')->ignore($levelId)],
            'order' => 'nullable|integer|min:1|max:99',
            'description' => 'nullable|string|max:1000',
        ];
    }

    public function attributes()
    {
        return [
            'name' => 'اسم المستوى',
            'code' => 'الرمز',
            'order' => 'الترتيب',
            'description' => 'الوصف',
        ];
    }
}
