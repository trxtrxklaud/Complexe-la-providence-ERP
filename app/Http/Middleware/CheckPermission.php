<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'حسابك معطَّل. تواصل مع المشرف.'], 403);
        }

        if (! $user->relationLoaded('role') || ! $user->role?->relationLoaded('permissions')) {
            $user->load('role.permissions');
        }

        $role = $user->role;

        if (! $role) {
            return response()->json(['message' => 'عذراً، لا تملك صلاحية للوصول'], 403);
        }

        // المسار الأساسي: الصلاحية ممنوحة صراحةً في جدول الصلاحيات.
        if ($role->permissions->contains('name', $permission)) {
            return $next($request);
        }

        // شبكة أمان قابلة للضبط عبر PERMISSION_SUPER_ROLES بدل تثبيت الاسم 'admin' في الكود.
        $superRoles = (array) config('permissions.super_roles', []);

        if (in_array($role->name, $superRoles, true)) {
            return $next($request);
        }

        return response()->json(['message' => 'عذراً، لا تملك صلاحية للوصول'], 403);
    }
}
