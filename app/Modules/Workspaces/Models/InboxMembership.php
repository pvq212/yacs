<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Models;

use App\Support\Database\TenantModel;

/**
 * 座席可服務的收件匣（明確範圍）。
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $inbox_id
 * @property string $membership_id
 */
final class InboxMembership extends TenantModel
{
    protected $table = 'inbox_memberships';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
