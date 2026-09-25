<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Models;

use App\Support\Database\TenantModel;

/**
 * 客服團隊。
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string $status
 * @property int $version
 */
final class Team extends TenantModel
{
    protected $table = 'teams';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
