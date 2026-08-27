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

        if ($user->hasPermissionTo($permission)) {
            return $next($request);
        }

        return response()->json(['message' => 'عذراً، لا تملك صلاحية للوصول'], 403);
    }
}
