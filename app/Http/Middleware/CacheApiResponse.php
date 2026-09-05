<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ميدلوير التخزين المؤقت للبيانات المرجعية وترويسات ETag و Cache-Control.
 * يُطبّق حصراً على طلبات GET الناجحة (200 OK) لتقليل الحمل على خادم KVM 1.
 */
class CacheApiResponse
{
    public function handle(Request $request, Closure $next, int $maxAge = 1800, int $staleWhileRevalidate = 3600): Response
    {
        // 1. تمرير الطلبات غير التابعة لـ GET مباشرة دون كاش
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $next($request);
        }

        $response = $next($request);

        // 2. تطبيق الكاش فقط على الاستجابات الناجحة 200 OK
        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        // 3. احتساب ETag من محتوى الاستجابة
        $content = $response->getContent();
        if ($content !== false) {
            $etag = '"' . md5($content) . '"';
            $response->headers->set('ETag', $etag);

            // 4. فحص If-None-Match لدعم 304 Not Modified
            $ifNoneMatch = $request->header('If-None-Match');
            if ($ifNoneMatch) {
                $clientEtags = array_map('trim', explode(',', $ifNoneMatch));
                if (in_array($etag, $clientEtags, true) || in_array(trim($etag, '"'), $clientEtags, true) || in_array('*', $clientEtags, true)) {
                    return response('', 304, [
                        'ETag' => $etag,
                        'Cache-Control' => sprintf('private, max-age=%d, stale-while-revalidate=%d', $maxAge, $staleWhileRevalidate),
                    ]);
                }
            }
        }

        // 5. ضبط ترويسات Cache-Control
        $response->headers->set(
            'Cache-Control',
            sprintf('private, max-age=%d, stale-while-revalidate=%d', $maxAge, $staleWhileRevalidate)
        );

        return $response;
    }
}
