<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLevelRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:20|unique:levels,code',
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
