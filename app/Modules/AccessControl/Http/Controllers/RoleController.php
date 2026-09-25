<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Http\Controllers;

use App\Modules\AccessControl\Authorizer;
use App\Modules\AccessControl\Models\Role;
use App\Modules\AccessControl\Permission;
use App\Modules\AccessControl\RoleGrantGuard;
use App\Modules\AccessControl\StaffActor;
use App\Modules\Audit\AuditActor;
use App\Modules\Audit\AuditLogger;
use App\Support\Database\Cas;
use App\Support\Database\Seq;
use App\Support\Http\ApiException;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use App\Support\Http\Input;
use App\Support\Http\KeysetPaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 角色（OpenAPI: listRole / createRole / getRole / updateRole）。
 *
 * 讀取需 roles.assign 或 staff.manage；寫入需 roles.assign 並通過 RoleGrantGuard。
 */
final class RoleController
{
    public function index(Request $request, StaffActor $actor, Authorizer $authz): JsonResponse
    {
        $this->authorizeRead($authz);
        $page = Input::validate($request, Input::pageRules());

        return ApiResponse::page(KeysetPaginator::paginate(
            Role::query()->forWorkspace($actor->workspaceId()), [['key', 'asc'], ['id', 'asc']],
            KeysetPaginator::clampLimit($page['limit'] ?? null), $page['cursor'] ?? null,
            static fn (Role $r): array => self::resource($r),
        ));
    }

    public function show(string $workspace_id, string $role_id, StaffActor $actor, Authorizer $authz): JsonResponse
    {
        $this->authorizeRead($authz);

        return ApiResponse::data(self::resource($this->find($actor, $role_id)));
    }

    public function store(Request $request, StaffActor $actor, RoleGrantGuard $guard, AuditLogger $audit): JsonResponse
    {
        $data = Input::validate($request, [
            'key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_.-]{1,63}$/'],
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'permission_codes' => ['present', 'array', 'max:100'],
            'permission_codes.*' => ['string', 'distinct', 'in:'.implode(',', array_column(Permission::cases(), 'value'))],
        ]);
        $guard->assertCanDefine($data['permission_codes'], null, 'role.create');

        $role = new Role;
        try {
            DB::transaction(function () use ($role, $data, $actor, $audit): void {
                $role->forceFill([
                    'workspace_id' => $actor->workspaceId(),
                    'key' => $data['key'],
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'is_system_template' => false,
                ])->save();
                $this->syncPermissions($role, $data['permission_codes']);
                $audit->record($actor->workspaceId(), AuditActor::staff($actor), 'role.created', 'role', $role->id, null, self::resource($role));
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException(ErrorCode::ValidationFailed, null, ['fields' => ['key' => ['taken']]]);
        }

        return ApiResponse::data(self::resource($role->refresh()), 201);
    }

    public function update(Request $request, string $workspace_id, string $role_id, StaffActor $actor, Authorizer $authz, RoleGrantGuard $guard, AuditLogger $audit): JsonResponse
    {
        $role = $this->find($actor, $role_id);
        $data = Input::validate($request, [
            'key' => ['sometimes', 'string', 'regex:/^[a-z][a-z0-9_.-]{1,63}$/'],
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'permission_codes' => ['sometimes', 'array', 'max:100'],
            'permission_codes.*' => ['string', 'distinct', 'in:'.implode(',', array_column(Permission::cases(), 'value'))],
            'expected_version' => Input::seq(),
        ]);
        if ($role->is_system_template && isset($data['key']) && $data['key'] !== $role->key) {
            throw ApiException::invalidState('system_role_key_immutable');
        }

        $current = self::permissionCodes($role);
        $next = $data['permission_codes'] ?? $current;
        $changed = array_values(array_unique([...array_diff($next, $current), ...array_diff($current, $next)]));
        // 修改角色需擁有「新增/移除」的每個權限；只改名稱也需 roles.assign。
        $guard->assertCanDefine($changed, $role->id, 'role.update');

        try {
            DB::transaction(function () use ($role, $data, $next, $actor, $audit, $guard): void {
                $before = self::resource($role);
                Cas::update($role, $data['expected_version'], array_intersect_key($data, array_flip(['key', 'name', 'description'])));
                if (array_key_exists('permission_codes', $data)) {
                    $this->syncPermissions($role, $next);
                    $guard->assertOwnerRemains($actor->workspaceId());
                }
                $audit->record($actor->workspaceId(), AuditActor::staff($actor), 'role.updated', 'role', $role->id, $before, self::resource($role));
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException(ErrorCode::ValidationFailed, null, ['fields' => ['key' => ['taken']]]);
        }
        $authz->refresh();

        return ApiResponse::data(self::resource($role));
    }

    /**
     * @return array<string, mixed>
     */
    public static function resource(Role $role): array
    {
        return [
            'id' => $role->id,
            'workspace_id' => $role->workspace_id,
            'version' => Seq::str($role->version),
            'key' => $role->key,
            'name' => $role->name,
            'description' => $role->description,
            'is_system_template' => $role->is_system_template,
            'permission_codes' => self::permissionCodes($role),
        ];
    }

    /**
     * @return list<string>
     */
    public static function permissionCodes(Role $role): array
    {
        return DB::table('role_permissions')->where('role_id', $role->id)->orderBy('permission_code')
            ->pluck('permission_code')->map(static fn ($c): string => (string) $c)->all();
    }

    private function authorizeRead(Authorizer $authz): void
    {
        if (! $authz->can(Permission::RolesAssign) && ! $authz->can(Permission::StaffManage)) {
            throw ApiException::forbidden();
        }
    }

    private function find(StaffActor $actor, string $roleId): Role
    {
        $role = Input::isUuid($roleId) ? Role::query()->forWorkspace($actor->workspaceId())->find($roleId) : null;
        if ($role === null) {
            throw ApiException::notFound();
        }

        return $role;
    }

    /**
     * @param  list<string>  $codes
     */
    private function syncPermissions(Role $role, array $codes): void
    {
        DB::table('role_permissions')->where('role_id', $role->id)->whereNotIn('permission_code', $codes)->delete();
        DB::table('role_permissions')->insertOrIgnore(array_map(static fn (string $c): array => [
            'workspace_id' => $role->workspace_id,
            'role_id' => $role->id,
            'permission_code' => $c,
        ], $codes));
    }
}
