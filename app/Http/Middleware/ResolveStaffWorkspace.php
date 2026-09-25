<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\AccessControl\Authorizer;
use App\Modules\AccessControl\StaffActor;
use App\Modules\Identity\Models\StaffSessionSecurity;
use App\Modules\Identity\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Models\WorkspaceMembership;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use App\Support\Http\Principal;
use App\Support\Tenancy\TenantDatabase;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 解析路徑中的 `{workspace_id}`：確認目前 staff 在該 workspace 有啟用中的 membership，
 * 然後設定資料庫租戶範圍並建立本請求的 StaffActor 與 Authorizer。
 *
 * 不存在或無 membership 一律回 404，不透露 workspace 是否存在（SEC-001）。
 */
final class ResolveStaffWorkspace
{
    public function __construct(private readonly TenantDatabase $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();
        $workspaceId = (string) $request->route('workspace_id');
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $workspaceId) !== 1) {
            throw new ApiException(ErrorCode::NotFound);
        }

        // RLS policy `yacs_membership_self` 讓使用者在進入 workspace 前可讀取自己的 membership。
        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user->id)
            ->first();
        if ($membership === null || ! $membership->isActive()) {
            throw new ApiException(ErrorCode::NotFound);
        }

        $this->tenant->enterWorkspace($workspaceId);
        $workspace = Workspace::query()->find($workspaceId);
        if ($workspace === null) {
            throw new ApiException(ErrorCode::NotFound);
        }
        if (! $workspace->isActive()) {
            throw new ApiException(ErrorCode::Forbidden, null, ['reason' => 'workspace_suspended']);
        }

        $security = app()->bound(StaffSessionSecurity::class) ? app(StaffSessionSecurity::class) : null;
        $actor = new StaffActor($user, $workspace, $membership, $security);
        app()->instance(StaffActor::class, $actor);
        app()->instance(Principal::class, $actor);
        app()->instance(Authorizer::class, new Authorizer($actor));

        return $next($request);
    }
}
