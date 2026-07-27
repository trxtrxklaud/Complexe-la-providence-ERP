<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * يمنع الحسابات المعطَّلة من الوصول إلى أي مسار محمي،
     * حتى المسارات التي لا تمرّ بفحص الصلاحيات (مثل الموظفين والرواتب سابقاً).
     * يُطبَّق داخل مجموعة auth:sanctum بعد أن يُحلّ المستخدِم.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            return response()->json(
                ['message' => 'حسابك معطَّل. تواصل مع المشرف.'],
                403
            );
        }

        return $next($request);
    }
}
