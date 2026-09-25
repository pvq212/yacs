<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API 請求限制：一般 payload 上限 64 KiB（附件走專用上傳流程），寫入請求必須是 JSON 物件。
 *
 * 例外：webhook 入站路由需要原始 body 驗章，另由 controller 處理（仍受大小限制）。
 */
final class EnforceJsonPayload
{
    public function handle(Request $request, Closure $next): Response
    {
        $max = (int) config('yacs.messages.request_max_bytes', 65536);
        $length = strlen($request->getContent());
        if ($length > $max) {
            throw new ApiException(ErrorCode::PayloadTooLarge, null, ['max_bytes' => $max]);
        }

        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true) && $length > 0 && ! $request->is('api/v1/hooks/*')) {
            $decoded = json_decode($request->getContent(), true);
            if (! $request->isJson() || ! is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
                throw new ApiException(ErrorCode::ValidationFailed, null, ['body' => ['must_be_json_object']]);
            }
        }

        return $next($request);
    }
}
