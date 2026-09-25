<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Models;

use App\Support\Database\Model;

/**
 * 全域唯讀權限目錄（由 PermissionCatalogSeeder 依 Permission enum 同步）。
 *
 * @property string $code
 * @property string $category
 * @property string $description
 */
final class Permission extends Model
{
    protected $table = 'permissions';

    protected $primaryKey = 'code';

    public $timestamps = false;

    public function uniqueIds(): array
    {
        return [];
    }
}
