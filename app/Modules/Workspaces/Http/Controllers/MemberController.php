<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Http\Controllers;

use App\Modules\AccessControl\Authorizer;
use App\Modules\AccessControl\Models\RoleBinding;
use App\Modules\AccessControl\Permission;
use App\Modules\AccessControl\RoleGrantGuard;
use App\Modules\AccessControl\StaffActor;
use App\Modules\Audit\AuditActor;
use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Staff\InvitationService;
use App\Modules\Identity\Staff\StaffSessionManager;
use App\Modules\Workspaces\Models\AgentCapacity;
use App\Modules\Workspaces\Models\WorkspaceMembership;
use App\Support\Database\Cas;
use App\Support\Database\Seq;
use App\Support\Http\ApiException;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use App\Support\Http\Input;
use App\Support\Http\KeysetPaginator;
use App\Support\Tenancy\TenantDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 人員（OpenAPI: listMember / createMember / getMember / updateMember）
 * 與角色授予（getMemberRoleBindings / replaceMemberRoleBindings）。
 *
 * 需 staff.manage；角色授予另需 roles.assign 並通過 RoleGrantGuard。
 * 停用成員時立即撤銷其所有 staff session（SEC-009：60 秒內失效）。
 */
final class MemberController
{
    public function index(Request $request, StaffActor $actor, Authorizer $authz): JsonResponse
    {
        $authz->authorize(Permission::StaffManage);
        $params = Input::validate($request, [...Input::pageRules(), 'status' => ['sometimes', 'string', 'in:invited,active,disabled']]);
        $query = WorkspaceMembership::query()->forWorkspace($actor->workspaceId())->with('user');
        if (isset($params['status'])) {
            $query->where('status', $params['status']);
        }

        return ApiResponse::page(KeysetPaginator::paginate(
            $query, [['display_name', 'asc'], ['id', 'asc']],
            KeysetPaginator::clampLimit($params['limit'] ?? null), $params['cursor'] ?? null,
            static fn (WorkspaceMembership $m): array => self::resource($m),
        ));
    }

    public function show(string $workspace_id, string $member_id, StaffActor $actor, Authorizer $authz): JsonResponse
    {
        $authz->authorize(Permission::StaffManage);

        return ApiResponse::data(self::resource($this->find($actor, $member_id)));
    }

    public function store(Request $request, StaffActor $actor, Authorizer $authz, InvitationService $invitations, AuditLogger $audit): JsonResponse
    {
        $authz->authorize(Permission::StaffManage);
        $data = Input::validate($request, [
            'email' => ['required', 'string', 'email:rfc', 'max:320'],
            'display_name' => ['required', 'string', 'min:1', 'max:100'],
            'status' => ['required', 'string', 'in:invited'],
            'max_active_conversations' => ['sometimes', 'integer:strict', 'min:1', 'max:100'],
            'inbox_ids' => ['sometimes', 'array', 'max:200'],
            'inbox_ids.*' => ['string', 'uuid', 'distinct'],
            'team_ids' => ['sometimes', 'array', 'max:200'],
            'team_ids.*' => ['string', 'uuid', 'distinct'],
        ]);
        $this->assertReferences($actor, $data);

        $membership = DB::transaction(function () use ($data, $actor, $invitations, $audit): WorkspaceMembership {
            $email = User::normalizeEmail($data['email']);
            $user = User::query()->where('email_normalized', $email)->lockForUpdate()->first();
            if ($user === null) {
                $user = new User;
                $user->forceFill([
                    'email' => trim($data['email']),
                    'email_normalized' => $email,
                    'name' => $data['display_name'],
                    'status' => 'active',
                ])->save();
            }
            if (WorkspaceMembership::query()->forWorkspace($actor->workspaceId())->where('user_id', $user->id)->exists()) {
                throw new ApiException(ErrorCode::InvalidState, null, ['reason' => 'member_exists']);
            }

            $membership = new WorkspaceMembership;
            $membership->forceFill([
                'workspace_id' => $actor->workspaceId(),
                'user_id' => $user->id,
                'display_name' => $data['display_name'],
                'status' => 'invited',
            ])->save();
            (new AgentCapacity)->forceFill([
                'workspace_id' => $actor->workspaceId(),
                'membership_id' => $membership->id,
                'max_active' => $data['max_active_conversations'] ?? (int) config('yacs.conversation.default_agent_capacity', 5),
                'presence_status' => 'offline',
                'updated_at' => CarbonImmutable::now(),
            ])->save();
            $this->syncLinks($membership, $data);
            $invitations->invite($membership, $user, $actor->membership);
            $audit->record($actor->workspaceId(), AuditActor::staff($actor), 'member.invited', 'member', $membership->id, null, self::resource($membership));

            return $membership;
        });

        return ApiResponse::data(self::resource($membership->refresh()), 201);
    }

    public function update(Request $request, string $workspace_id, string $member_id, StaffActor $actor, Authorizer $authz, StaffSessionManager $sessions, RoleGrantGuard $guard, AuditLogger $audit): JsonResponse
    {
        $authz->authorize(Permission::StaffManage);
        $membership = $this->find($actor, $member_id);
        $data = Input::validate($request, [
            'display_name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'status' => ['sometimes', 'string', 'in:invited,active,disabled'],
            'max_active_conversations' => ['sometimes', 'integer:strict', 'min:1', 'max:100'],
            'inbox_ids' => ['sometimes', 'array', 'max:200'],
            'inbox_ids.*' => ['string', 'uuid', 'distinct'],
            'team_ids' => ['sometimes', 'array', 'max:200'],
            'team_ids.*' => ['string', 'uuid', 'distinct'],
            'expected_version' => Input::seq(),
        ]);
        $this->assertReferences($actor, $data);
        if (($data['status'] ?? null) === 'active' && $membership->status === 'invited') {
            // 啟用需由受邀者本人接受邀請，避免管理員替他人啟用帳號。
            throw ApiException::invalidState('invitation_not_accepted');
        }
        if ($membership->id === $actor->membershipId() && ($data['status'] ?? null) === 'disabled') {
            throw ApiException::invalidState('cannot_disable_self');
        }

        DB::transaction(function () use ($membership, $data, $actor, $sessions, $guard, $audit): void {
            $before = self::resource($membership);
            Cas::update($membership, $data['expected_version'], array_intersect_key($data, array_flip(['display_name', 'status'])));
            if (isset($data['max_active_conversations'])) {
                AgentCapacity::query()->where('membership_id', $membership->id)->update(['max_active' => $data['max_active_conversations'], 'updated_at' => CarbonImmutable::now()]);
            }
            $this->syncLinks($membership, $data);
            if (($data['status'] ?? null) === 'disabled') {
                $guard->assertOwnerRemains($actor->workspaceId());
                $this->revokeIfNoActiveMembership($membership, $sessions);
            }
            $audit->record($actor->workspaceId(), AuditActor::staff($actor), 'member.updated', 'member', $membership->id, $before, self::resource($membership));
        });

        return ApiResponse::data(self::resource($membership));
    }

    public function roleBindings(string $workspace_id, string $member_id, StaffActor $actor, Authorizer $authz): JsonResponse
    {
        if (! $authz->can(Permission::RolesAssign) && ! $authz->can(Permission::StaffManage)) {
            throw ApiException::forbidden();
        }
        $membership = $this->find($actor, $member_id);

        return ApiResponse::data(['member_id' => $membership->id, 'role_bindings' => self::bindings($membership->id)]);
    }

    /**
     * PUT：以完整清單取代成員的角色授予。新增與移除的每一筆都要通過防提權檢查。
     */
    public function replaceRoleBindings(Request $request, string $workspace_id, string $member_id, StaffActor $actor, Authorizer $authz, RoleGrantGuard $guard, AuditLogger $audit): JsonResponse
    {
        $membership = $this->find($actor, $member_id);
        $data = Input::validate($request, [
            'role_bindings' => ['present', 'array', 'max:50'],
            'role_bindings.*' => ['array:role_id,scope_type,scope_id'],
            'role_bindings.*.role_id' => ['required', 'string', 'uuid'],
            'role_bindings.*.scope_type' => ['required', 'string', 'in:workspace,brand,inbox,team'],
            'role_bindings.*.scope_id' => ['present', 'nullable', 'string', 'uuid'],
        ]);

        $desired = [];
        foreach ($data['role_bindings'] as $i => $b) {
            if (($b['scope_type'] === 'workspace') !== ($b['scope_id'] === null)) {
                throw new ApiException(ErrorCode::ValidationFailed, null, ['fields' => ["role_bindings.{$i}.scope_id" => ['invalid_for_scope_type']]]);
            }
            $desired[$b['role_id'].'|'.$b['scope_type'].'|'.($b['scope_id'] ?? '')] = $b;
        }
        $current = [];
        foreach (self::bindings($membership->id) as $b) {
            $current[$b['role_id'].'|'.$b['scope_type'].'|'.($b['scope_id'] ?? '')] = $b;
        }
        $added = array_diff_key($desired, $current);
        $removed = array_diff_key($current, $desired);

        $rolePerms = $this->rolePermissions($actor, array_unique(array_column([...$added, ...$removed], 'role_id')));
        foreach ([...array_values($added), ...array_values($removed)] as $b) {
            if (! isset($rolePerms[$b['role_id']])) {
                throw new ApiException(ErrorCode::ValidationFailed, null, ['fields' => ['role_bindings' => ['role_not_found']]]);
            }
            $guard->assertCanGrant($b['role_id'], $rolePerms[$b['role_id']], $b['scope_type'], $b['scope_id'], 'member.role_bindings');
        }

        DB::transaction(function () use ($membership, $added, $removed, $actor, $guard, $audit, $current): void {
            foreach ($removed as $b) {
                RoleBinding::query()->where('membership_id', $membership->id)->where('role_id', $b['role_id'])
                    ->where('scope_type', $b['scope_type'])
                    ->when($b['scope_id'] === null, fn ($q) => $q->whereNull('scope_id'), fn ($q) => $q->where('scope_id', $b['scope_id']))
                    ->delete();
            }
            foreach ($added as $b) {
                (new RoleBinding)->forceFill([
                    'workspace_id' => $actor->workspaceId(),
                    'membership_id' => $membership->id,
                    'role_id' => $b['role_id'],
                    'scope_type' => $b['scope_type'],
                    'scope_id' => $b['scope_id'],
                    'granted_by_membership_id' => $actor->membershipId(),
                    'created_at' => CarbonImmutable::now(),
                ])->save();
            }
            $guard->assertOwnerRemains($actor->workspaceId());
            $audit->record($actor->workspaceId(), AuditActor::staff($actor), 'member.role_bindings_replaced', 'member', $membership->id,
                ['role_bindings' => array_values($current)], ['role_bindings' => self::bindings($membership->id)]);
        });

        return ApiResponse::data(['member_id' => $membership->id, 'role_bindings' => self::bindings($membership->id)]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function resource(WorkspaceMembership $m): array
    {
        return [
            'id' => $m->id,
            'workspace_id' => $m->workspace_id,
            'version' => Seq::str($m->version),
            'email' => $m->user->email,
            'display_name' => $m->display_name,
            'status' => $m->status,
            'max_active_conversations' => (int) (AgentCapacity::query()->where('membership_id', $m->id)->value('max_active') ?? config('yacs.conversation.default_agent_capacity', 5)),
            'inbox_ids' => DB::table('inbox_memberships')->where('membership_id', $m->id)->orderBy('inbox_id')->pluck('inbox_id')->map(static fn ($v): string => (string) $v)->all(),
            'team_ids' => DB::table('team_memberships')->where('membership_id', $m->id)->orderBy('team_id')->pluck('team_id')->map(static fn ($v): string => (string) $v)->all(),
        ];
    }

    /**
     * @return list<array{role_id: string, scope_type: string, scope_id: string|null}>
     */
    public static function bindings(string $membershipId): array
    {
        return DB::table('role_bindings')->where('membership_id', $membershipId)->orderBy('role_id')->orderBy('scope_type')->orderBy('scope_id')
            ->get(['role_id', 'scope_type', 'scope_id'])
            ->map(static fn ($r): array => ['role_id' => (string) $r->role_id, 'scope_type' => (string) $r->scope_type, 'scope_id' => $r->scope_id !== null ? (string) $r->scope_id : null])
            ->all();
    }

    private function find(StaffActor $actor, string $memberId): WorkspaceMembership
    {
        $m = Input::isUuid($memberId) ? WorkspaceMembership::query()->forWorkspace($actor->workspaceId())->with('user')->find($memberId) : null;
        if ($m === null) {
            throw ApiException::notFound();
        }

        return $m;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertReferences(StaffActor $actor, array $data): void
    {
        foreach (['inbox_ids' => 'inboxes', 'team_ids' => 'teams'] as $field => $table) {
            $ids = $data[$field] ?? [];
            if ($ids !== [] && DB::table($table)->where('workspace_id', $actor->workspaceId())->whereIn('id', $ids)->count() !== count($ids)) {
                throw new ApiException(ErrorCode::ValidationFailed, null, ['fields' => [$field => ['not_found']]]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncLinks(WorkspaceMembership $membership, array $data): void
    {
        $now = CarbonImmutable::now();
        foreach (['inbox_ids' => ['inbox_memberships', 'inbox_id'], 'team_ids' => ['team_memberships', 'team_id']] as $field => [$table, $column]) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $ids = $data[$field];
            DB::table($table)->where('membership_id', $membership->id)->whereNotIn($column, $ids)->delete();
            DB::table($table)->insertOrIgnore(array_map(static fn (string $id): array => [
                'id' => (string) Str::uuid7(),
                'workspace_id' => $membership->workspace_id,
                $column => $id,
                'membership_id' => $membership->id,
                'created_at' => $now,
            ], $ids));
        }
    }

    /**
     * @param  list<string>  $roleIds
     * @return array<string, list<string>>
     */
    private function rolePermissions(StaffActor $actor, array $roleIds): array
    {
        $out = [];
        $roles = DB::table('roles')->where('workspace_id', $actor->workspaceId())->whereIn('id', $roleIds)->pluck('id');
        foreach ($roles as $roleId) {
            $out[(string) $roleId] = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission_code')->map(static fn ($c): string => (string) $c)->all();
        }

        return $out;
    }

    private function revokeIfNoActiveMembership(WorkspaceMembership $membership, StaffSessionManager $sessions): void
    {
        // 使用者可能屬於多個 workspace：只停用此 workspace 時不需登出其他 workspace，
        // 但本 workspace 的授權在下一個請求即失效（ResolveStaffWorkspace 檢查 membership 狀態）。
        // 已建立的 realtime 訂閱由 fanout 端每次發送前重查。
        $hasOther = app(TenantDatabase::class)->asSystem(static fn (): bool => DB::table('workspace_memberships')
            ->where('user_id', $membership->user_id)->where('status', 'active')->exists());
        if (! $hasOther) {
            $sessions->revokeAllForUser($membership->user_id);
        }
    }
}
