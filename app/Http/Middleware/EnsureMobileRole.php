<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| EnsureMobileRole — يحصر المسار على دور جوال محدّد (parent | teacher)
|--------------------------------------------------------------------------
|
| ملف جديد بالكامل. لا يستبدل CheckPermission بل يكمّله: مسارات الجوال
| تُجمَّع خلف هذا الحارس ثم خلف permission:<name> عند اللزوم.
|
*/

class EnsureMobileRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'حسابك معطَّل. تواصل مع المشرف.'], 403);
        }

        if ($user->role?->name !== $role) {
            return response()->json(['message' => 'عذراً، لا تملك صلاحية للوصول'], 403);
        }

        return $next($request);
    }
}
