<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GmailLoginController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'phone' => 'required|string',
        ], [
            'email.required' => 'البريد الإلكتروني مطلوب.',
            'email.email' => 'البريد الإلكتروني غير صالح.',
            'phone.required' => 'رقم الهاتف مطلوب.',
        ]);

        $email = trim((string) $request->email);
        $phone = trim((string) $request->phone);

        // البحث عن مستخدم بالإيميل
        $user = User::query()
            ->with(['role.permissions', 'permissionOverrides.permission'])
            ->where('email', $email)
            ->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الإيميل غير مسجل في النظام',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'حسابك معطَّل. تواصل مع إدارة المدرسة.',
            ], 403);
        }

        // التحقق من رقم الهاتف (كلمة السر)
        $storedPhone = $user->phone_password ?? $user->phone;

        // تطبيع الأرقام للمقارنة (استخراج الأرقام فقط)
        $normalizedPhone = preg_replace('/[^0-9]/', '', $phone);
        $normalizedStored = preg_replace('/[^0-9]/', '', (string) $storedPhone);

        if (! $normalizedPhone || $normalizedPhone !== $normalizedStored) {
            return response()->json([
                'success' => false,
                'message' => 'رقم الهاتف غير صحيح',
            ], 401);
        }

        // إنشاء Token
        $effectivePermissions = $user->getEffectivePermissionNames();
        $token = $user->createToken('gmail-login', $effectivePermissions)->plainTextToken;

        $roleName = $user->role?->name ?? 'parent';

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الدخول بنجاح',
            'data' => [
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $roleName,
                ],
            ],
        ], 200);
    }
}
