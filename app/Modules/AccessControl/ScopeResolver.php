<?php

declare(strict_types=1);

namespace App\Modules\AccessControl;

use Illuminate\Support\Facades\DB;

/**
 * 由資源 ID 組出授權用的 ResourceScope（同一請求內快取收件匣的品牌與團隊）。
 *
 * 查詢一律帶 workspace 條件；找不到時回 null，呼叫端應回 404。
 */
final class ScopeResolver
{
    /** @var array<string, ResourceScope|null> */
    private array $inboxCache = [];

    public function __construct(private readonly StaffActor $actor) {}

    public function forInbox(string $inboxId): ?ResourceScope
    {
        if (array_key_exists($inboxId, $this->inboxCache)) {
            return $this->inboxCache[$inboxId];
        }
        $brandId = DB::table('inboxes')->where('workspace_id', $this->actor->workspaceId())->where('id', $inboxId)->value('brand_id');
        if ($brandId === null) {
            return $this->inboxCache[$inboxId] = null;
        }
        $teams = DB::table('inbox_teams')->where('inbox_id', $inboxId)->pluck('team_id')->map(static fn ($t): string => (string) $t)->all();

        return $this->inboxCache[$inboxId] = new ResourceScope((string) $brandId, $inboxId, null, $teams);
    }

    /**
     * 對話的範圍：所在收件匣 + 對話目前指定的團隊。
     */
    public function forConversation(string $inboxId, ?string $teamId): ?ResourceScope
    {
        $inbox = $this->forInbox($inboxId);

        return $inbox === null ? null : new ResourceScope($inbox->brandId, $inbox->inboxId, $teamId, $inbox->inboxTeamIds);
    }

    public function forBrand(string $brandId): ?ResourceScope
    {
        $exists = DB::table('brands')->where('workspace_id', $this->actor->workspaceId())->where('id', $brandId)->exists();

        return $exists ? ResourceScope::brand($brandId) : null;
    }

    public function forTeam(string $teamId): ?ResourceScope
    {
        $exists = DB::table('teams')->where('workspace_id', $this->actor->workspaceId())->where('id', $teamId)->exists();

        return $exists ? ResourceScope::team($teamId) : null;
    }

    /**
     * 角色授予目標範圍 → ResourceScope（用於「授予者本身是否涵蓋此範圍」檢查）。
     */
    public function forBinding(string $scopeType, ?string $scopeId): ?ResourceScope
    {
        return match ($scopeType) {
            'workspace' => $scopeId === null ? ResourceScope::workspace() : null,
            'brand' => $scopeId !== null ? $this->forBrand($scopeId) : null,
            'inbox' => $scopeId !== null ? $this->forInbox($scopeId) : null,
            'team' => $scopeId !== null ? $this->forTeam($scopeId) : null,
            default => null,
        };
    }
}
