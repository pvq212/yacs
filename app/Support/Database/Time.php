<?php

declare(strict_types=1);

namespace App\Support\Database;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * 對外時間格式：RFC3339 UTC（例如 2026-09-26T00:00:00.000Z）。
 */
final class Time
{
    public static function iso(?DateTimeInterface $time): ?string
    {
        if ($time === null) {
            return null;
        }

        return CarbonImmutable::instance($time)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
