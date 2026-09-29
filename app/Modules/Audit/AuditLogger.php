<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Support\Security\LookupDigest;
use App\Support\Tenancy\TenantDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 寫入稽核紀錄（append-only）。
 *
 * - `record()`：與業務變更同交易寫入；交易 rollback 時稽核也一併消失（變更沒發生）。
 * - `recordDenied()`：被拒絕的敏感操作（例如自我提權，SEC-005）。因為請求交易會 rollback，
 *   先暫存在記憶體，由 FlushDeferredAudit middleware 在交易結束後寫入。
 *
 * before/after 會經 SafeSummary 過濾，任何疑似 secret/token/password 的欄位都不會寫入。
 */
final class AuditLogger
{
    /** @var list<array<string, mixed>> */
    private array $deferred = [];

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $scope
     */
    public function record(
        string $workspaceId,
        AuditActor $actor,
        string $action,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        array $scope = [],
    ): void {
        DB::table('audit_logs')->insert($this->row($workspaceId, $actor, $action, $resourceType, $resourceId, $before, $after, $reason, $scope));
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function recordDenied(string $workspaceId, AuditActor $actor, string $action, ?string $resourceType = null, ?string $resourceId = null, array $details = []): void
    {
        $this->deferred[] = $this->row($workspaceId, $actor, $action.'.denied', $resourceType, $resourceId, null, $details, 'denied', []);
    }

    /**
     * 寫入暫存的拒絕紀錄。呼叫端需確保此時不在會被 rollback 的交易中。
     */
    public function flushDeferred(): void
    {
        if ($this->deferred === []) {
            return;
        }
        $rows = $this->deferred;
        $this->deferred = [];
        $tenant = app(TenantDatabase::class);
        foreach ($rows as $row) {
            // 拒絕紀錄可能發生在尚未進入 workspace 的情境，以明確 workspace 範圍寫入。
            $tenant->withinWorkspace((string) $row['workspace_id'], static fn () => DB::table('audit_logs')->insert($row));
        }
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function row(string $workspaceId, AuditActor $actor, string $action, ?string $resourceType, ?string $resourceId, ?array $before, ?array $after, ?string $reason, array $scope): array
    {
        $request = request();

        return [
            'id' => (string) Str::uuid7(),
            'workspace_id' => $workspaceId,
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'scope' => json_encode((object) $scope, JSON_UNESCAPED_UNICODE),
            'request_id' => Context::get('request_id'),
            'ip_digest' => LookupDigest::ip($request->ip()),
            'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
            'before_safe' => $before !== null ? json_encode(SafeSummary::filter($before), JSON_UNESCAPED_UNICODE) : null,
            'after_safe' => $after !== null ? json_encode(SafeSummary::filter($after), JSON_UNESCAPED_UNICODE) : null,
            'created_at' => CarbonImmutable::now(),
        ];
    }
}
