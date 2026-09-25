<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Models;

use App\Support\Database\TenantModel;

/**
 * 允許嵌入 widget 的來源（正規化 scheme://host[:port]）。
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $inbox_id
 * @property string $origin
 */
final class InboxOrigin extends TenantModel
{
    protected $table = 'inbox_origins';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
