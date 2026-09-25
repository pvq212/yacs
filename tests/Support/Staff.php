<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Workspaces\Models\WorkspaceMembership;

/**
 * 測試用 staff：使用者 + 某 workspace 的 membership。
 */
final readonly class Staff
{
    public function __construct(
        public User $user,
        public WorkspaceMembership $membership,
        public World $world,
    ) {}

    public function id(): string
    {
        return $this->membership->id;
    }
}
