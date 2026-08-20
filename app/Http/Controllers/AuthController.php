<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
    public function login(Request $request)
    {
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
