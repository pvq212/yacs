<?php

declare(strict_types=1);

namespace App\Modules\AccessControl;

use App\Modules\Identity\Models\StaffSessionSecurity;
use App\Modules\Identity\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Models\WorkspaceMembership;
use App\Support\Http\Principal;

/**
 * 已驗證 staff 在「單一、明確 workspace」中的行為者上下文。
 *
 * 由 ResolveStaffWorkspace middleware 建立並綁定為 scoped（每請求重建），
 * 不可放入 singleton 或 static，以免 Octane 長駐程序跨請求殘留（SEC-006）。
 */
final readonly class StaffActor implements Principal
{
    public function __construct(
        public User $user,
        public Workspace $workspace,
        public WorkspaceMembership $membership,
        public ?StaffSessionSecurity $session,
    ) {}

    public function workspaceId(): string
    {
        return $this->workspace->id;
    }

    public function membershipId(): string
    {
        return $this->membership->id;
    }

    public function principalScope(): string
    {
        return 'staff:'.$this->membership->id;
    }

    public function sessionId(): ?string
    {
        return $this->session?->id;
    }
}
