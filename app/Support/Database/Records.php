<?php

declare(strict_types=1);

namespace App\Support\Database;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Query builder 使用的明確資料轉換，避免序列化任意資料庫列成公開回應。 */
final class Records
{
    public static function id(): string
    {
        return (string) Str::uuid7();
    }

    public static function json(mixed $value): array
    {
        return is_array($value) ? $value : (json_decode((string) $value, true) ?: []);
    }

    public static function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function date(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toISOString();
    }
}
