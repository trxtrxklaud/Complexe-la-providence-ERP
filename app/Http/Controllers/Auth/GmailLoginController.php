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
            'email' => 'required|string',
            'phone' => 'required|string',
        ], [
            'email.required' => 'البريد الإلكتروني أو اسم المستخدم مطلوب.',
            'phone.required' => 'رقم الهاتف مطلوب.',
        ]);

        $identifier = trim((string) ($request->email ?? $request->username ?? $request->pseudo ?? $request->identifier));
        $phone = trim((string) $request->phone);

        // البحث عن مستخدم بالإيميل أو اسم المستخدم (Pseudo)
        $user = User::query()
            ->with(['role.permissions', 'permissionOverrides.permission'])
            ->where(function ($q) use ($identifier) {
                $q->where('email', $identifier)
                  ->orWhere('username', $identifier);
            })
            ->first();

        if (! $user) {
            $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);
            $msg = $isEmail ? 'هذا الإيميل غير مسجل في النظام' : 'اسم المستخدم (Pseudo) هذا غير مسجل في النظام';
            return response()->json([
                'success' => false,
                'message' => $msg,
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
                    'username' => $user->username,
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
