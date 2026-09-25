<?php

declare(strict_types=1);

namespace App\Modules\AccessControl;

/**
 * 已展開的單筆權限授予：某權限在某範圍有效。
 */
final readonly class Grant
{
    public function __construct(
        public Permission $permission,
        public string $scopeType,
        public ?string $scopeId,
    ) {}
}
