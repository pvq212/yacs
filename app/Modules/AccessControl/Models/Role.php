<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Models;

use App\Support\Database\TenantModel;

/**
 * 角色：一組權限碼；系統模板角色可調整權限但不可刪除。
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property bool $is_system_template
 * @property int $version
 */
final class Role extends TenantModel
{
    protected $table = 'roles';

    protected function casts(): array
    {
        return [
            'is_system_template' => 'boolean',
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
