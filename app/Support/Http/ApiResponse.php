<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;

/**
 * 統一回應封套（docs/spec/API_CONTRACT.md §3）：
 *   成功 `{data, meta:{request_id}}`
 *   列表 `{data:[], meta:{next_cursor, has_more, request_id}}`
 *
 * data 必須是由 Resource/DTO 明確組出的陣列，禁止直接序列化 Eloquent model。
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>|list<mixed>|null  $data
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public static function data(?array $data, int $status = 200, array $meta = [], array $headers = []): JsonResponse
    {
        return new JsonResponse(
            ['data' => $data, 'meta' => array_merge($meta, ['request_id' => self::requestId()])],
            $status,
            $headers,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public static function list(array $items, ?string $nextCursor = null, array $meta = []): JsonResponse
    {
        return self::data($items, 200, array_merge($meta, [
            'next_cursor' => $nextCursor,
            'has_more' => $nextCursor !== null,
        ]));
    }

    public static function page(CursorPage $page): JsonResponse
    {
        return self::list($page->items, $page->nextCursor);
    }

    public static function noContent(): JsonResponse
    {
        return new JsonResponse(null, 204);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function error(ErrorCode $code, ?string $message = null, array $details = [], array $headers = []): JsonResponse
    {
        return new JsonResponse(
            [
                'error' => [
                    'code' => $code->value,
                    'message' => $message ?? $code->defaultMessage(),
                    'details' => (object) $details,
                    'request_id' => self::requestId(),
                ],
            ],
            $code->status(),
            $headers,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    public static function requestId(): string
    {
        return (string) (Context::get('request_id') ?? 'req_unknown');
    }
}
