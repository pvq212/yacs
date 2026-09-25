<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * 一頁 keyset 分頁結果。items 已轉為對外 DTO 陣列。
 */
final readonly class CursorPage
{
    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {}
}
