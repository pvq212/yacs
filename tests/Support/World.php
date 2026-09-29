<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\AccessControl\Models\Role;
use App\Modules\AccessControl\Models\RoleBinding;
use App\Modules\AccessControl\RoleTemplates;
use App\Modules\Identity\Models\User;
use App\Modules\Workspaces\Actions\ProvisionWorkspace;
use App\Modules\Workspaces\Models\AgentCapacity;
use App\Modules\Workspaces\Models\Brand;
use App\Modules\Workspaces\Models\Inbox;
use App\Modules\Workspaces\Models\Team;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Models\WorkspaceMembership;
use App\Support\Security\Tokens;
use App\Support\Tenancy\TenantDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 測試資料建構器：以真實 Action/資料表建立 workspace、品牌、收件匣、成員與角色。
 *
 * 所有寫入都在明確的 workspace 範圍內進行（RLS 生效），結束後還原租戶設定。
 */
final class World
{
    public const PASSWORD = 'correct-horse-battery-9';

    public Workspace $workspace;

    public User $owner;

    public WorkspaceMembership $ownerMembership;

    private function __construct() {}

    public static function create(string $name = 'Acme', ?string $slug = null): self
    {
        $world = new self;
        $world->owner = self::user('owner-'.Str::lower(Str::random(6)).'@example.test', $name.' Owner');
        $world->workspace = app(ProvisionWorkspace::class)->handle($name, $slug ?? Str::slug($name).'-'.Str::lower(Str::random(6)), $world->owner);
        $world->ownerMembership = $world->in(fn () => WorkspaceMembership::query()->where('user_id', $world->owner->id)->firstOrFail());

        return $world;
    }

    public static function user(string $email, string $name = 'Test User', bool $withPassword = true): User
    {
        $user = new User;
        $user->forceFill([
            'email' => $email,
            'email_normalized' => User::normalizeEmail($email),
            'name' => $name,
            'password_hash' => $withPassword ? Hash::make(self::PASSWORD) : null,
            'status' => 'active',
        ])->save();

        return $user;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function in(callable $callback): mixed
    {
        return app(TenantDatabase::class)->withinWorkspace($this->workspace->id, $callback(...));
    }

    public function brand(string $name = 'Main Brand'): Brand
    {
        return $this->in(function () use ($name): Brand {
            $brand = new Brand;
            $brand->forceFill([
                'workspace_id' => $this->workspace->id,
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
                'status' => 'active',
                'settings' => ['theme' => ['display_name' => $name]],
            ])->save();

            return $brand->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function inbox(Brand $brand, string $name = 'Website', array $overrides = []): Inbox
    {
        return $this->in(function () use ($brand, $name, $overrides): Inbox {
            $inbox = new Inbox;
            $inbox->forceFill(array_merge([
                'workspace_id' => $this->workspace->id,
                'brand_id' => $brand->id,
                'name' => $name,
                'public_key' => Tokens::publicKey('ibx_'),
                'channel_type' => 'web',
                'status' => 'active',
                'ai_mode' => 'disabled',
                'settings' => [],
            ], $overrides))->save();
            DB::table('inbox_origins')->insert([
                'id' => (string) Str::uuid7(),
                'workspace_id' => $this->workspace->id,
                'inbox_id' => $inbox->id,
                'origin' => 'https://shop.example.test',
                'created_at' => CarbonImmutable::now(),
            ]);

            return $inbox->refresh();
        });
    }

    public function team(string $name = 'Tier 1'): Team
    {
        return $this->in(function () use ($name): Team {
            $team = new Team;
            $team->forceFill(['workspace_id' => $this->workspace->id, 'name' => $name.' '.Str::random(4), 'status' => 'active'])->save();

            return $team->refresh();
        });
    }

    /**
     * 建立成員並授予角色。
     *
     * @param  list<array{0: string, 1?: string, 2?: string|null}>  $roles  [role key, scope type, scope id]
     * @param  list<Inbox>  $inboxes  座席可服務的收件匣
     */
    public function member(string $name, array $roles = [[RoleTemplates::AGENT]], array $inboxes = [], int $capacity = 5, ?User $user = null): Staff
    {
        $user ??= self::user(Str::slug($name).'-'.Str::lower(Str::random(6)).'@example.test', $name);

        return $this->in(function () use ($user, $name, $roles, $inboxes, $capacity): Staff {
            $membership = new WorkspaceMembership;
            $membership->forceFill([
                'workspace_id' => $this->workspace->id,
                'user_id' => $user->id,
                'display_name' => $name,
                'status' => 'active',
            ])->save();
            (new AgentCapacity)->forceFill([
                'workspace_id' => $this->workspace->id,
                'membership_id' => $membership->id,
                'max_active' => $capacity,
                'presence_status' => 'offline',
                'updated_at' => CarbonImmutable::now(),
            ])->save();
            foreach ($roles as $role) {
                $this->grant($membership, $role[0], $role[1] ?? 'workspace', $role[2] ?? null);
            }
            foreach ($inboxes as $inbox) {
                DB::table('inbox_memberships')->insert([
                    'id' => (string) Str::uuid7(),
                    'workspace_id' => $this->workspace->id,
                    'inbox_id' => $inbox->id,
                    'membership_id' => $membership->id,
                    'created_at' => CarbonImmutable::now(),
                ]);
            }

            return new Staff($user, $membership->refresh(), $this);
        });
    }

    public function ownerStaff(): Staff
    {
        return new Staff($this->owner, $this->ownerMembership, $this);
    }

    public function grant(WorkspaceMembership $membership, string $roleKey, string $scopeType = 'workspace', ?string $scopeId = null): void
    {
        $this->in(function () use ($membership, $roleKey, $scopeType, $scopeId): void {
            if ($roleKey === '__assign_only') {
                $roleKey = $this->customRole('assign-only', ['roles.assign', 'staff.manage']);
            }
            $roleId = Role::query()->where('workspace_id', $this->workspace->id)->where('key', $roleKey)->value('id');
            (new RoleBinding)->forceFill([
                'workspace_id' => $this->workspace->id,
                'membership_id' => $membership->id,
                'role_id' => $roleId,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'created_at' => CarbonImmutable::now(),
            ])->save();
        });
    }

    /**
     * 建立（或取得）只含指定權限的自訂角色，回傳 role key。
     *
     * @param  list<string>  $permissions
     */
    public function customRole(string $key, array $permissions): string
    {
        return $this->in(function () use ($key, $permissions): string {
            if (! Role::query()->where('workspace_id', $this->workspace->id)->where('key', $key)->exists()) {
                $role = new Role;
                $role->forceFill(['workspace_id' => $this->workspace->id, 'key' => $key, 'name' => $key, 'is_system_template' => false])->save();
                DB::table('role_permissions')->insert(array_map(fn (string $p): array => [
                    'workspace_id' => $this->workspace->id, 'role_id' => $role->id, 'permission_code' => $p,
                ], $permissions));
            }

            return $key;
        });
    }

    public function roleId(string $key): string
    {
        return (string) $this->in(fn () => Role::query()->where('workspace_id', $this->workspace->id)->where('key', $key)->value('id'));
    }
}
