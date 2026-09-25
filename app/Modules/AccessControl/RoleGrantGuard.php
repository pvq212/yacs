<?php

declare(strict_types=1);

namespace App\Modules\AccessControl;

use App\Modules\Audit\AuditActor;
use App\Modules\Audit\AuditLogger;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * 防止自我提權（SEC-005）：
 *
 * 1. 定義/修改角色的權限：操作者必須在 workspace 範圍擁有「新增與移除」的每一個權限。
 *    因此運營管理員無法修改 Owner 角色，也無法建立含自己沒有權限的角色。
 * 2. 授予/撤銷角色：操作者需在 workspace 範圍有 roles.assign，且對「目標範圍」擁有該角色的每一個權限。
 * 3. 不可讓 workspace 失去最後一位有效 Owner（避免鎖死）。
 *
 * 被拒絕的嘗試會寫入稽核（交易結束後寫入，不受 rollback 影響）。
 */
final class RoleGrantGuard
{
    public function __construct(
        private readonly Authorizer $authz,
        private readonly ScopeResolver $scopes,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $permissionCodes  變更涉及的權限（新增 ∪ 移除）
     */
    public function assertCanDefine(array $permissionCodes, ?string $roleId, string $action): void
    {
        $this->authz->authorize(Permission::RolesAssign);
        foreach (Permission::fromCodes($permissionCodes) as $permission) {
            if (! $this->authz->can($permission, ResourceScope::workspace())) {
                $this->deny($action, $roleId, ['permission' => $permission->value]);
            }
        }
    }

    /**
     * @param  list<string>  $rolePermissionCodes
     */
    public function assertCanGrant(string $roleId, array $rolePermissionCodes, string $scopeType, ?string $scopeId, string $action): void
    {
        $this->authz->authorize(Permission::RolesAssign);
        $target = $this->scopes->forBinding($scopeType, $scopeId);
        if ($target === null) {
            throw new ApiException(ErrorCode::ValidationFailed, null, ['fields' => ['scope_id' => ['not_found']]]);
        }
        foreach (Permission::fromCodes($rolePermissionCodes) as $permission) {
            if (! $this->authz->can($permission, $target)) {
                $this->deny($action, $roleId, ['permission' => $permission->value, 'scope_type' => $scopeType, 'scope_id' => $scopeId]);
            }
        }
    }

    /**
     * 變更後，workspace 至少要有一位啟用中、在 workspace 範圍擁有全部 Owner 權限的成員。
     */
    public function assertOwnerRemains(string $workspaceId): void
    {
        $required = count(Permission::cases());
        $owners = DB::table('role_bindings as b')
            ->join('workspace_memberships as m', 'm.id', '=', 'b.membership_id')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'b.role_id')
            ->where('b.workspace_id', $workspaceId)
            ->where('b.scope_type', 'workspace')
            ->where('m.status', 'active')
            ->where('u.status', 'active')
            ->groupBy('b.membership_id')
            ->havingRaw('count(distinct rp.permission_code) >= ?', [$required])
            ->select('b.membership_id')
            ->get();
        if ($owners->isEmpty()) {
            throw ApiException::invalidState('last_owner');
        }
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function deny(string $action, ?string $roleId, array $details): never
    {
        $actor = $this->authz->actor();
        $this->audit->recordDenied($actor->workspaceId(), AuditActor::staff($actor), $action, 'role', $roleId, $details);

        throw new ApiException(ErrorCode::Forbidden, null, ['reason' => 'privilege_escalation', ...$details]);
    }
}
