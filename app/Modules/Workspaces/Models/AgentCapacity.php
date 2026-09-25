<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Models;

use App\Support\Database\TenantModel;
use Carbon\CarbonImmutable;

/**
 * 座席容量與 presence；分派交易會以 FOR UPDATE 鎖定此列。
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $membership_id
 * @property int $max_active
 * @property string $presence_status
 * @property CarbonImmutable|null $last_heartbeat_at
 * @property CarbonImmutable|null $last_assigned_at
 * @property CarbonImmutable|null $offline_since
 */
final class AgentCapacity extends TenantModel
{
    protected $table = 'agent_capacity';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'max_active' => 'integer',
            'last_heartbeat_at' => 'immutable_datetime',
            'last_assigned_at' => 'immutable_datetime',
            'offline_since' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
