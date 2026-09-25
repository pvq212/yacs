<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Models;

use App\Support\Database\TenantModel;
use Carbon\CarbonImmutable;

/**
 * staff 邀請；token 只存 hash，單次使用。
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $email_normalized
 * @property string $token_hash
 * @property string $membership_id
 * @property string|null $invited_by_membership_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $revoked_at
 */
final class StaffInvitation extends TenantModel
{
    protected $table = 'staff_invitations';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
