<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Models;

use App\Modules\Identity\Models\User;
use App\Support\Database\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 使用者在某 workspace 的成員資格；對話 assignee 指向 membership 而非全域 user。
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $user_id
 * @property string $display_name
 * @property string $status
 * @property int $version
 * @property-read User $user
 */
final class WorkspaceMembership extends TenantModel
{
    protected $table = 'workspace_memberships';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
