<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $userId = $this->route('user')->id;
        return [
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'username'   => ['required', 'string', 'max:255', Rule::unique('users')->ignore($userId)],
            'email'      => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($userId)],
            'phone'      => 'nullable|string|max:20',
            'password'   => 'nullable|string|min:8|confirmed',
            'role_id'    => 'required|exists:roles,id',
            'is_active'  => 'boolean',
        ];
    }
}
