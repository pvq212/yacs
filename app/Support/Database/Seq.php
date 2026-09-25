<?php

declare(strict_types=1);

namespace App\Support\Database;

/**
 * bigint 序號/版本對外一律以十進位字串表示（JavaScript Number 會失真）。
 */
final class Seq
{
    public static function str(int|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    /**
     * 驗證並解析外部傳入的十進位字串（不接受 JSON number、負數或前導零）。
     */
    public static function parse(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^(0|[1-9][0-9]{0,18})$/', $value) !== 1) {
            return null;
        }
        // 不超過 PostgreSQL bigint 上限。
        if (strlen($value) === 19 && strcmp($value, '9223372036854775807') > 0) {
            return null;
        }

        return $value;
    }
}
