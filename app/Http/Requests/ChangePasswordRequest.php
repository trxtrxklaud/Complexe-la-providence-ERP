<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * تغيير كلمة مرور مستخدم من قِبل المشرف مباشرةً (دون كلمة المرور القديمة).
 * الحراسة تتم عبر الوسيط permission:manage_users على المسار، فلا حاجة لتفويض هنا.
 */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required'  => 'كلمة المرور إجبارية.',
            'password.min'       => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.',
            'password.confirmed' => 'تأكيد كلمة المرور غير مطابق.',
        ];
    }
}
