<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * API 錯誤碼與對應 HTTP 狀態（docs/spec/SPEC.md §13.1）。
 *
 * 新增錯誤碼時需同步更新 docs/spec/contracts/openapi.yaml 的 ErrorCode enum、
 * lang/<locale>/errors.php 的訊息，以及前端 SDK 型別。
 */
enum ErrorCode: string
{
    case Unauthenticated = 'UNAUTHENTICATED';
    case MfaRequired = 'MFA_REQUIRED';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case VersionConflict = 'VERSION_CONFLICT';
    case IdempotencyConflict = 'IDEMPOTENCY_CONFLICT';
    case CapacityExceeded = 'CAPACITY_EXCEEDED';
    case InvalidState = 'INVALID_STATE';
    case IdentityReplayed = 'IDENTITY_REPLAYED';
    case NewConversationRequired = 'NEW_CONVERSATION_REQUIRED';
    case CursorExpired = 'CURSOR_EXPIRED';
    case ValidationFailed = 'VALIDATION_FAILED';
    case CapabilityUnsupported = 'CAPABILITY_UNSUPPORTED';
    case PayloadTooLarge = 'PAYLOAD_TOO_LARGE';
    case RateLimited = 'RATE_LIMITED';
    case TemporarilyUnavailable = 'TEMPORARILY_UNAVAILABLE';
    case InternalError = 'INTERNAL_ERROR';

    public function status(): int
    {
        return match ($this) {
            self::Unauthenticated, self::MfaRequired => 401,
            self::Forbidden => 403,
            self::NotFound => 404,
            self::VersionConflict, self::IdempotencyConflict, self::CapacityExceeded,
            self::InvalidState, self::IdentityReplayed, self::NewConversationRequired => 409,
            self::CursorExpired => 410,
            self::PayloadTooLarge => 413,
            self::ValidationFailed, self::CapabilityUnsupported => 422,
            self::RateLimited => 429,
            self::TemporarilyUnavailable => 503,
            self::InternalError => 500,
        };
    }

    /**
     * 使用者可讀的預設訊息（依目前 locale）。
     */
    public function defaultMessage(): string
    {
        return __('errors.'.$this->value);
    }
}
