<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreUserRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'nullable|string|max:20',
            'password' => ['required', 'string', Password::min(10)->letters()->numbers(), 'confirmed'],
            'role_id' => 'required|exists:roles,id',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $actor = $this->user();
            $targetRole = Role::find($this->input('role_id'));
            $superRoles = (array) config('permissions.super_roles', []);

            if (
                $actor
                && $targetRole
                && in_array($targetRole->name, $superRoles, true)
                && ! in_array($actor->role?->name, $superRoles, true)
            ) {
                $validator->errors()->add('role_id', 'لا تملك صلاحية إسناد هذا الدور.');
            }
        });
    }
}
