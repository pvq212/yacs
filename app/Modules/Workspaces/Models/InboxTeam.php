<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Models;

use App\Support\Database\TenantModel;

/**
 * 收件匣與團隊的服務關係。
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $inbox_id
 * @property string $team_id
 */
final class InboxTeam extends TenantModel
{
    protected $table = 'inbox_teams';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
