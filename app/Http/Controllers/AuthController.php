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

    $user = User::with('role.permissions')
                ->where('email', $request->email)
                ->first();

    if (!$user || !Hash::check($request->password, $user->password)) {
        return response()->json([
            'message' => 'بيانات الدخول غير صحيحة'
        ], 401);
    }

    if (isset($user->is_active) && !$user->is_active) {
        return response()->json([
            'message' => 'هذا الحساب موقوف، تواصل مع المسؤول'
        ], 403);
    }

    $abilities = $user->role?->permissions
                      ->pluck('name')
                      ->toArray() ?? [];

    $token = $user->createToken('auth_token', $abilities)->plainTextToken;

    return response()->json([
        'message'      => 'تم تسجيل الدخول بنجاح',
        'access_token' => $token,
        'token_type'   => 'Bearer',
        'user'         => $user,
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
        return response()->json($request->user()->load('role.permissions'));
    }
}
