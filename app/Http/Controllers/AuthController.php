<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use App\Services\AuditService;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // 1. التحقق من Turnstile أولاً
        $turnstileSecret = config('services.cloudflare.turnstile_secret');
        if ($turnstileSecret && !app()->environment('testing')) {
            $turnstileToken = $request->input('cf-turnstile-response') ?? $request->input('turnstile_token');
            if ($turnstileToken) {
                $turnstileResponse = Http::asForm()->post(
                    'https://challenges.cloudflare.com/turnstile/v0/siteverify',
                    [
                        'secret'   => $turnstileSecret,
                        'response' => $turnstileToken,
                        'remoteip' => $request->ip(),
                    ]
                );

                $turnstileData = $turnstileResponse->json();

                if (!($turnstileData['success'] ?? false)) {
                    return response()->json([
                        'message' => 'فشل التحقق الأمني. حاول مرة أخرى.'
                    ], 422);
                }
            } else {
                return response()->json([
                    'message' => 'يرجى إكمال التحقق الأمني.'
                ], 422);
            }
        }

        $request->validate([
            'email'    => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = User::with(['role.permissions', 'permissionOverrides.permission'])
                    ->where('email', $request->email)
                    ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'بيانات الدخول غير صحيحة'
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'هذا الحساب موقوف، تواصل مع المسؤول'
            ], 403);
        }

        $effectivePermissions = $user->getEffectivePermissionNames();

        $token = $user->createToken('auth_token', $effectivePermissions)->plainTextToken;

        // تسجيل الدخول عبر رمز مميّز لا يمرّ بحارس، فلا منفّذ مضبوطاً بعد؛
        // نضبطه صراحةً كي يلتقط سجل التدقيق هوية الداخل.
        Auth::setUser($user);
        AuditService::log('login', 'تسجيل الدخول إلى النظام');

        $userArray = $user->toArray();
        $userArray['effective_permissions'] = $effectivePermissions;

        return response()->json([
            'message'      => 'تم تسجيل الدخول بنجاح',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $userArray,
        ]);
    }

    public function logout(Request $request)
    {
        AuditService::log('logout', 'تسجيل الخروج من النظام');

        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'تم تسجيل الخروج بنجاح'
        ]);
    }

    public function user(Request $request)
    {
        $user = $request->user()->loadMissing(['role.permissions', 'permissionOverrides.permission']);
        $userArray = $user->toArray();
        $userArray['effective_permissions'] = $user->getEffectivePermissionNames();

        return response()->json($userArray);
    }
}
