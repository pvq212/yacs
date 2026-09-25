<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Http\Controllers;

use App\Modules\AccessControl\Authorizer;
use App\Modules\AccessControl\Permission;
use App\Modules\AccessControl\StaffActor;
use App\Modules\Audit\AuditActor;
use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Workspaces\Http\Resources\OpsResources;
use App\Modules\Workspaces\Models\Workspace;
use App\Support\Database\Cas;
use App\Support\Http\ApiResponse;
use App\Support\Http\Input;
use App\Support\Http\KeysetPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Workspace 基本資料（OpenAPI tag: Operations）。
 */
final class WorkspaceController
{
    /**
     * GET /api/v1/workspaces：目前使用者有啟用 membership 的 workspace。
     * RLS policy `yacs_workspace_member` 只讓使用者看見自己所屬的 workspace。
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();
        $query = Workspace::query()
            ->where('status', 'active')
            ->whereIn('id', DB::table('workspace_memberships')->select('workspace_id')->where('user_id', $user->id)->where('status', 'active'));

        return ApiResponse::page(KeysetPaginator::paginate(
            $query,
            [['name', 'asc'], ['id', 'asc']],
            KeysetPaginator::clampLimit($request->query('limit')),
            $request->query('cursor') !== null ? (string) $request->query('cursor') : null,
            static fn (Workspace $w): array => OpsResources::workspace($w),
        ));
    }

    public function show(StaffActor $actor): JsonResponse
    {
        return ApiResponse::data(OpsResources::workspace($actor->workspace));
    }

    public function update(Request $request, StaffActor $actor, Authorizer $authz, AuditLogger $audit): JsonResponse
    {
        $authz->authorize(Permission::WorkspaceManage);
        $data = Input::validate($request, [
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'expected_version' => Input::seq(),
        ]);

        return DB::transaction(function () use ($data, $actor, $audit): JsonResponse {
            $workspace = $actor->workspace;
            $before = OpsResources::workspace($workspace);
            $changes = array_intersect_key($data, array_flip(['name', 'timezone']));
            Cas::update($workspace, $data['expected_version'], $changes);
            $audit->record($workspace->id, AuditActor::staff($actor), 'workspace.updated', 'workspace', $workspace->id, $before, OpsResources::workspace($workspace));

            return ApiResponse::data(OpsResources::workspace($workspace));
        });
    }

    /**
     * GET /api/v1/workspaces/{workspace_id}/me：目前使用者在此 workspace 的 membership 與權限，
     * 供前端決定顯示哪些功能（實際授權仍在伺服器端逐一判斷）。
     */
    public function me(StaffActor $actor, Authorizer $authz): JsonResponse
    {
        $grants = [];
        foreach ($authz->permissionCodes() as $code) {
            $permission = Permission::from($code);
            foreach ($authz->grantsFor($permission) as $grant) {
                $grants[] = ['permission' => $code, 'scope_type' => $grant->scopeType, 'scope_id' => $grant->scopeId];
            }
        }
        sort($grants);

        return ApiResponse::data([
            'workspace' => OpsResources::workspace($actor->workspace),
            'membership_id' => $actor->membershipId(),
            'display_name' => $actor->membership->display_name,
            'staff_session_id' => $actor->sessionId(),
            'permission_codes' => $authz->permissionCodes(),
            'grants' => $grants,
        ]);
    }
}
