<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Models;

use App\Support\Database\TenantModel;

/**
 * 團隊成員。
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $team_id
 * @property string $membership_id
 */
final class TeamMembership extends TenantModel
{
    protected $table = 'team_memberships';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
