<?php

declare(strict_types=1);

namespace App\Support\Http;

use RuntimeException;

/**
 * 可預期的 API 錯誤；會被轉成 `{error:{code,message,details,request_id}}`。
 *
 * details 只能放「可給呼叫者看」的非敏感資訊（例如 current_version），
 * 不可放 SQL、堆疊、供應商原始回應或任何 secret。
 */
final class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        ?string $message = null,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message ?? $errorCode->defaultMessage());
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function of(ErrorCode $code, array $details = [], ?string $message = null): self
    {
        return new self($code, $message, $details);
    }

    public static function notFound(): self
    {
        return new self(ErrorCode::NotFound);
    }

    public static function forbidden(): self
    {
        return new self(ErrorCode::Forbidden);
    }

    public static function versionConflict(string $currentVersion): self
    {
        return new self(ErrorCode::VersionConflict, null, ['current_version' => $currentVersion]);
    }

    public static function invalidState(string $reason): self
    {
        return new self(ErrorCode::InvalidState, null, ['reason' => $reason]);
    }
}
