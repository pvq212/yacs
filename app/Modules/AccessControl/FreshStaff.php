<?php

declare(strict_types=1);

namespace App\Modules\AccessControl;

use App\Modules\Identity\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Models\WorkspaceMembership;

/** 非 HTTP 任務每次重新檢查成員、帳號與工作空間狀態，不使用請求快取。 */
final class FreshStaff
{
    public static function resolve(?string $membership): ?StaffActor
    {
        if ($membership === null) {
            return null;
        }
        $member = WorkspaceMembership::query()->whereKey($membership)->where('status', 'active')->first();
        if ($member === null) {
            return null;
        }
        $user = User::query()->find($member->user_id);
        $workspace = Workspace::query()->whereKey($member->workspace_id)->where('status', 'active')->first();

        return $user !== null && $user->isActive() && $workspace !== null ? new StaffActor($user, $workspace, $member, null) : null;
    }
}
