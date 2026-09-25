<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 基本安全 header。widget iframe 頁面的 `frame-ancestors` 由 WidgetFrameController 依 inbox 設定另外輸出。
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if (! $headers->has('X-Frame-Options') && ! $headers->has('Content-Security-Policy')) {
            // 預設禁止被嵌入；widget 頁面會以 CSP frame-ancestors 覆寫。
            $headers->set('X-Frame-Options', 'DENY');
        }
        if ($request->is('api/*')) {
            $headers->set('Cache-Control', 'no-store');
        }
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
