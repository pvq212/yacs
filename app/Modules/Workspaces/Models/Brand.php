<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Models;

use App\Support\Database\TenantModel;

/**
 * 品牌：同一 workspace 下的不同品牌，contact 與知識預設不跨品牌共享。
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string $slug
 * @property string $status
 * @property array<string, mixed> $settings
 * @property int $version
 */
final class Brand extends TenantModel
{
    protected $table = 'brands';

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
