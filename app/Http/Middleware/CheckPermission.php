<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->role && $user->role->name === 'admin') {
            return $next($request);
        }

        if (!$user->role || !$user->role->permissions->contains('name', $permission)) {
            return response()->json(['message' => 'عذراً، لا تملك صلاحية للوصول'], 403);
        }

        return $next($request);
    }
}
