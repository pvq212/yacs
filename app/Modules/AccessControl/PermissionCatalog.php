<?php

declare(strict_types=1);

namespace App\Modules\AccessControl;

use Illuminate\Support\Facades\DB;

/**
 * 把 Permission enum 同步到全域 `permissions` 表（安裝與升級時執行，冪等）。
 */
final class PermissionCatalog
{
    public static function sync(): void
    {
        $rows = array_map(static fn (Permission $p): array => [
            'code' => $p->value,
            'category' => $p->category(),
            'description' => $p->description(),
        ], Permission::cases());

        DB::table('permissions')->upsert($rows, ['code'], ['category', 'description']);
    }
}
