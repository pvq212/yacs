<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * 把例外轉成統一錯誤格式 `{error:{code,message,details,request_id}}`（僅 API 路徑）。
 *
 * 原則：錯誤訊息面向使用者，不含 SQL、堆疊、供應商原始回應、token 或 secret；
 * 內部細節只寫入 log（log 本身也不記錄 Authorization/Cookie 等敏感 header）。
 */
final class ExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->dontReport([ApiException::class]);
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'assertion', 'refresh_token', 'code']);

        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => self::isApi($request) || $request->expectsJson(),
        );

        $exceptions->render(static function (Throwable $e, Request $request) {
            if (! self::isApi($request) && ! ($e instanceof ApiException && $request->expectsJson())) {
                return null;
            }

            return self::toResponse($e);
        });
    }

    public static function isApi(Request $request): bool
    {
        return $request->is('api/*');
    }

    public static function toResponse(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => ApiResponse::error($e->errorCode, $e->getMessage(), $e->details, $e->headers),
            $e instanceof ValidationException => ApiResponse::error(ErrorCode::ValidationFailed, null, ['fields' => $e->errors()]),
            $e instanceof AuthenticationException => ApiResponse::error(ErrorCode::Unauthenticated),
            $e instanceof AuthorizationException => ApiResponse::error(ErrorCode::Forbidden),
            $e instanceof TokenMismatchException => ApiResponse::error(ErrorCode::Forbidden, __('errors.CSRF_MISMATCH'), ['reason' => 'csrf']),
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiResponse::error(ErrorCode::NotFound),
            $e instanceof MethodNotAllowedHttpException => ApiResponse::error(ErrorCode::NotFound),
            $e instanceof ThrottleRequestsException => ApiResponse::error(
                ErrorCode::RateLimited,
                null,
                [],
                array_intersect_key($e->getHeaders(), ['Retry-After' => true]),
            ),
            $e instanceof PostTooLargeException => ApiResponse::error(ErrorCode::PayloadTooLarge),
            $e instanceof QueryException && self::isUnavailable($e) => ApiResponse::error(ErrorCode::TemporarilyUnavailable),
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 419 => ApiResponse::error(ErrorCode::Forbidden, __('errors.CSRF_MISMATCH'), ['reason' => 'csrf']),
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 429 => ApiResponse::error(ErrorCode::RateLimited, null, [], array_intersect_key($e->getHeaders(), ['Retry-After' => true])),
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 404 => ApiResponse::error(ErrorCode::NotFound),
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 401 => ApiResponse::error(ErrorCode::Unauthenticated),
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 503 => ApiResponse::error(ErrorCode::TemporarilyUnavailable),
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 403 => ApiResponse::error(ErrorCode::Forbidden),
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 413 => ApiResponse::error(ErrorCode::PayloadTooLarge),
            default => ApiResponse::error(ErrorCode::InternalError),
        };
    }

    /**
     * 連線失敗、序列化衝突與死結屬於暫時性錯誤：回 503 讓呼叫者安全重試（搭配冪等）。
     */
    private static function isUnavailable(QueryException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());

        return str_starts_with($state, '08') || in_array($state, ['40001', '40P01', '57P01', '57P03'], true);
    }
}
