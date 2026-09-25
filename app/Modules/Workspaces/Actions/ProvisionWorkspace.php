<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Actions;

use App\Modules\AccessControl\Models\Role;
use App\Modules\AccessControl\Models\RoleBinding;
use App\Modules\AccessControl\PermissionCatalog;
use App\Modules\AccessControl\RoleTemplates;
use App\Modules\Audit\AuditActor;
use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Workspaces\Models\AgentCapacity;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Models\WorkspaceMembership;
use App\Support\Tenancy\TenantDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 建立 workspace（僅 CLI / 安裝流程使用；首版不提供公開註冊，SPEC §00.1）。
 *
 * 在同一交易內：建立 workspace、複製系統角色模板、預設結案原因，並把指定使用者設為 Owner。
 */
final class ProvisionWorkspace
{
    public function __construct(
        private readonly TenantDatabase $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(string $name, string $slug, User $owner, string $timezone = 'Asia/Taipei', string $locale = 'zh_TW'): Workspace
    {
        PermissionCatalog::sync();

        return DB::transaction(function () use ($name, $slug, $owner, $timezone, $locale): Workspace {
            $workspaceId = (string) Str::uuid7();

            return $this->tenant->withinWorkspace($workspaceId, function () use ($workspaceId, $name, $slug, $owner, $timezone, $locale): Workspace {
                $workspace = new Workspace;
                $workspace->forceFill([
                    'id' => $workspaceId,
                    'name' => $name,
                    'slug' => $slug,
                    'timezone' => $timezone,
                    'default_locale' => $locale,
                    'status' => 'active',
                    'settings' => [],
                ])->save();

                $roleIds = $this->createTemplateRoles($workspaceId);
                $this->createDefaultResolutionReasons($workspaceId);

                $membership = new WorkspaceMembership;
                $membership->forceFill([
                    'workspace_id' => $workspaceId,
                    'user_id' => $owner->id,
                    'display_name' => $owner->name,
                    'status' => 'active',
                ])->save();

                (new AgentCapacity)->forceFill([
                    'workspace_id' => $workspaceId,
                    'membership_id' => $membership->id,
                    'max_active' => (int) config('yacs.conversation.default_agent_capacity', 5),
                    'presence_status' => 'offline',
                    'updated_at' => CarbonImmutable::now(),
                ])->save();

                (new RoleBinding)->forceFill([
                    'workspace_id' => $workspaceId,
                    'membership_id' => $membership->id,
                    'role_id' => $roleIds[RoleTemplates::OWNER],
                    'scope_type' => 'workspace',
                    'scope_id' => null,
                    'created_at' => CarbonImmutable::now(),
                ])->save();

                $this->audit->record($workspaceId, AuditActor::platform($owner->id), 'workspace.provisioned', 'workspace', $workspaceId, null, [
                    'name' => $name, 'slug' => $slug, 'owner_membership_id' => $membership->id,
                ]);

                return $workspace;
            });
        });
    }

    /**
     * @return array<string, string> role key => role id
     */
    public function createTemplateRoles(string $workspaceId): array
    {
        $ids = [];
        foreach (RoleTemplates::all() as $key => $template) {
            $role = new Role;
            $role->forceFill([
                'workspace_id' => $workspaceId,
                'key' => $key,
                'name' => $template['name'],
                'description' => $template['description'],
                'is_system_template' => true,
            ])->save();
            $ids[$key] = $role->id;
            DB::table('role_permissions')->insert(array_map(static fn ($p): array => [
                'workspace_id' => $workspaceId,
                'role_id' => $role->id,
                'permission_code' => $p->value,
            ], $template['permissions']));
        }

        return $ids;
    }

    private function createDefaultResolutionReasons(string $workspaceId): void
    {
        $now = CarbonImmutable::now();
        $defaults = [
            'answered' => '已回答問題',
            'resolved_by_customer' => '會員確認已解決',
            'duplicate' => '重複案件',
            'no_response' => '會員未回應',
            'spam' => '垃圾訊息',
            'other' => '其他',
        ];
        $position = 0;
        foreach ($defaults as $code => $label) {
            DB::table('resolution_reasons')->insert([
                'id' => (string) Str::uuid7(),
                'workspace_id' => $workspaceId,
                'code' => $code,
                'label' => $label,
                'status' => 'active',
                'position' => $position++,
                'version' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
