<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Builder;

/**
 * 含 workspace_id 的租戶資料 model。
 *
 * 查詢時請明確帶 workspace 條件（`forWorkspace()`），RLS 只是第二層防護，
 * 不能取代應用層的 scope 與 Policy。
 */
abstract class TenantModel extends Model
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForWorkspace(Builder $query, string $workspaceId): Builder
    {
        return $query->where($this->qualifyColumn('workspace_id'), $workspaceId);
    }
}
