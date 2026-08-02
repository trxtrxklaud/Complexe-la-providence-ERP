<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'username' => ['required', 'string', 'max:255', Rule::unique('users')->ignore($userId)],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($userId)],
            'phone' => 'nullable|string|max:20',
            'password' => 'nullable|string|min:8|confirmed',
            'role_id' => 'required|exists:roles,id',
            'is_active' => 'boolean',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $actor = $this->user();
            $targetUser = $this->route('user');
            $targetRole = Role::find($this->input('role_id'));
            $superRoles = (array) config('permissions.super_roles', []);

            if (! $actor || ! $targetUser) {
                return;
            }

            if ($actor->is($targetUser) && (int) $this->input('role_id') !== (int) $targetUser->role_id) {
                $validator->errors()->add('role_id', 'لا يمكنك تغيير دور حسابك الشخصي.');
            }

            if ($actor->is($targetUser) && $this->has('is_active') && ! $this->boolean('is_active')) {
                $validator->errors()->add('is_active', 'لا يمكنك تعطيل حسابك الشخصي.');
            }

            if (
                $targetRole
                && in_array($targetRole->name, $superRoles, true)
                && ! in_array($actor->role?->name, $superRoles, true)
            ) {
                $validator->errors()->add('role_id', 'لا تملك صلاحية إسناد هذا الدور.');
            }
        });
    }
}
