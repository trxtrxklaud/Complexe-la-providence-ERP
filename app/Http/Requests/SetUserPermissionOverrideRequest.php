<?php

namespace App\Http\Requests;

use App\Models\UserPermissionOverride;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetUserPermissionOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'permission_id' => [
                'required',
                'integer',
                'exists:permissions,id',
            ],
            'effect' => [
                'required',
                'string',
                Rule::in(UserPermissionOverride::VALID_EFFECTS),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'permission_id.required' => 'حقل الصلاحية مطلوب.',
            'permission_id.exists'   => 'الصلاحية المحددة غير موجودة.',
            'effect.required'        => 'حقل نوع الاستثناء مطلوب.',
            'effect.in'              => 'نوع الاستثناء يجب أن يكون grant أو deny.',
        ];
    }
}
