<?php

declare(strict_types=1);

namespace App\Modules\AccessControl;

use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Staff 權限判斷：有效能力 = 已認證 + membership 有效 + 權限碼 + 資源範圍（SPEC §07.1）。
 *
 * 每個請求建立一個實例（scoped binding），首次使用時載入該 membership 的全部授予，
 * 之後在記憶體判斷。權限撤銷在下一個請求立即生效；已建立的 socket 由 fanout 端重查。
 */
final class Authorizer
{
    /** @var array<string, list<Grant>>|null permission code => grants */
    private ?array $grants = null;

    public function __construct(private readonly StaffActor $actor) {}

    public function actor(): StaffActor
    {
        return $this->actor;
    }

    public function can(Permission $permission, ?ResourceScope $scope = null): bool
    {
        $scope ??= ResourceScope::workspace();
        foreach ($this->grantsFor($permission) as $grant) {
            if ($scope->isCoveredBy($grant->scopeType, $grant->scopeId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 是否在任何範圍擁有此權限（用於「列表頁是否可進入」，實際資料仍需逐筆/SQL 過濾）。
     */
    public function canAnywhere(Permission $permission): bool
    {
        return $this->grantsFor($permission) !== [];
    }

    /**
     * @throws ApiException 403
     */
    public function authorize(Permission $permission, ?ResourceScope $scope = null): void
    {
        if (! $this->can($permission, $scope)) {
            throw new ApiException(ErrorCode::Forbidden, null, ['permission' => $permission->value]);
        }
    }

    /**
     * @throws ApiException 403
     */
    public function authorizeAnywhere(Permission $permission): void
    {
        if (! $this->canAnywhere($permission)) {
            throw new ApiException(ErrorCode::Forbidden, null, ['permission' => $permission->value]);
        }
    }

    /**
     * @return list<Grant>
     */
    public function grantsFor(Permission $permission): array
    {
        $this->load();

        return $this->grants[$permission->value] ?? [];
    }

    /**
     * 目前 actor 擁有的所有權限碼（任意範圍）。
     *
     * @return list<string>
     */
    public function permissionCodes(): array
    {
        $this->load();

        return array_keys(array_filter($this->grants ?? [], static fn (array $g): bool => $g !== []));
    }

    /**
     * 權限在單一請求中被修改（例如自己的角色被改）後，重新載入。
     */
    public function refresh(): void
    {
        $this->grants = null;
    }

    private function load(): void
    {
        if ($this->grants !== null) {
            return;
        }
        $this->grants = [];

        if (! $this->actor->membership->isActive() || ! $this->actor->user->isActive()) {
            return;
        }

        $rows = DB::table('role_bindings as b')
            ->join('role_permissions as rp', function ($join): void {
                $join->on('rp.role_id', '=', 'b.role_id')->on('rp.workspace_id', '=', 'b.workspace_id');
            })
            ->where('b.workspace_id', $this->actor->workspaceId())
            ->where('b.membership_id', $this->actor->membershipId())
            ->get(['rp.permission_code', 'b.scope_type', 'b.scope_id']);

        $workspaceOnly = array_map(static fn (Permission $p): string => $p->value, Permission::workspaceOnly());

        foreach ($rows as $row) {
            $permission = Permission::tryFrom((string) $row->permission_code);
            if ($permission === null) {
                continue;
            }
            // workspace 層級權限若被綁在較小範圍，視為無效（避免誤以為 brand 範圍可管理全域設定）。
            if ($row->scope_type !== 'workspace' && in_array($permission->value, $workspaceOnly, true)) {
                continue;
            }
            $this->grants[$permission->value][] = new Grant($permission, (string) $row->scope_type, $row->scope_id !== null ? (string) $row->scope_id : null);
        }
    }
}
