<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use LogicException;

/**
 * 管理 PostgreSQL session 的租戶設定（RLS 的第二層防護）。
 *
 * 運作方式（docs/adr/0004-postgres-rls-roles.md）：
 *  - `enterWorkspace()` 設定 `yacs.workspace_id`，所有啟用 RLS 的表只會看到該 workspace 的列。
 *  - `asSystem()` 暫時 `SET ROLE yacs_system`（BYPASSRLS），僅供明確的跨租戶系統工作，
 *    例如以 public key 找 inbox、以 token hash 找訪客 session、outbox 掃描。
 *  - `reset()` 在每個 HTTP 請求（Octane）與每個 queue job 前後呼叫，避免長駐程序殘留。
 *
 * PostgreSQL 的 SET 具交易語意：交易 rollback 時設定也會一併還原，因此例外路徑不會殘留角色。
 *
 * 注意：此類別刻意不做「記住上一個 workspace」之類的全域狀態，狀態只存在 DB session 與
 * 本物件（每個請求/任務重新建立，綁定為 scoped）。
 */
final class TenantDatabase
{
    private ?string $workspaceId = null;

    private ?string $userId = null;

    private int $systemDepth = 0;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly string $connectionName,
        private readonly string $systemRole,
    ) {}

    /**
     * 進入某個 workspace 的資料範圍。
     */
    public function enterWorkspace(string $workspaceId): void
    {
        $this->assertUuid($workspaceId);
        $this->connection()->select("SELECT set_config('yacs.workspace_id', ?, false)", [$workspaceId]);
        $this->workspaceId = $workspaceId;
    }

    /**
     * 設定目前 staff 使用者（僅讓「我所屬的 workspace/membership」可被讀取）。
     */
    public function setUser(?string $userId): void
    {
        if ($userId !== null) {
            $this->assertUuid($userId);
        }
        $this->connection()->select("SELECT set_config('yacs.user_id', ?, false)", [$userId ?? '']);
        $this->userId = $userId;
    }

    public function currentWorkspaceId(): ?string
    {
        return $this->workspaceId;
    }

    public function currentUserId(): ?string
    {
        return $this->userId;
    }

    /**
     * 暫時以 BYPASSRLS 系統角色執行。
     *
     * 只能用於「在還不知道 workspace 之前」的查找或平台層批次工作；
     * 回呼中不得處理來自使用者、未經驗證的資料範圍擴大。
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function asSystem(Closure $callback): mixed
    {
        $connection = $this->connection();
        if ($this->systemDepth === 0) {
            $connection->statement('SET ROLE '.$this->quotedRole());
        }
        $this->systemDepth++;

        try {
            return $callback();
        } finally {
            $this->systemDepth--;
            if ($this->systemDepth === 0) {
                $this->safeStatement('RESET ROLE');
            }
        }
    }

    /**
     * 在指定 workspace 範圍內執行，結束後還原先前範圍。
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withinWorkspace(string $workspaceId, Closure $callback): mixed
    {
        $previous = $this->workspaceId;
        $this->enterWorkspace($workspaceId);

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                $this->clearWorkspace();
            } else {
                $this->enterWorkspace($previous);
            }
        }
    }

    public function isSystem(): bool
    {
        return $this->systemDepth > 0;
    }

    /**
     * 清除所有租戶設定並回到 runtime 角色。請求/任務邊界必呼叫。
     *
     * @param  bool  $rollbackOpenTransactions  長駐程序在請求/任務「結束後」呼叫時設為 true，
     *                                          丟棄意外未結束的交易；請求開始時不可使用
     *                                          （測試會以外層交易包住整個請求）。
     */
    public function reset(bool $rollbackOpenTransactions = false): void
    {
        $this->systemDepth = 0;
        $this->workspaceId = null;
        $this->userId = null;
        $connection = $this->connection();

        // 若連線停在未結束的交易中，先 rollback（交易內的 SET 也會一併還原）。
        if ($rollbackOpenTransactions) {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }

        $connection->statement('RESET ROLE');
        $connection->select("SELECT set_config('yacs.workspace_id', '', false), set_config('yacs.user_id', '', false)");
    }

    public function clearWorkspace(): void
    {
        $this->connection()->select("SELECT set_config('yacs.workspace_id', '', false)");
        $this->workspaceId = null;
    }

    /**
     * 重新連線後恢復設定（Laravel 斷線重連會開新 PG session）。
     */
    public function reapply(): void
    {
        $connection = $this->connection();
        $connection->select(
            "SELECT set_config('yacs.workspace_id', ?, false), set_config('yacs.user_id', ?, false)",
            [$this->workspaceId ?? '', $this->userId ?? ''],
        );
        if ($this->systemDepth > 0) {
            $connection->statement('SET ROLE '.$this->quotedRole());
        }
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->connectionName);
    }

    private function safeStatement(string $sql): void
    {
        $connection = $this->connection();
        // 交易已 abort 時 RESET 會失敗；此時交易 rollback 會自動還原 SET ROLE。
        try {
            $connection->statement($sql);
        } catch (QueryException) {
            // 交給交易 rollback 還原；reset() 在請求邊界會再次確保乾淨。
        }
    }

    private function quotedRole(): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $this->systemRole) !== 1) {
            throw new LogicException('Invalid system role name.');
        }

        return '"'.$this->systemRole.'"';
    }

    private function assertUuid(string $value): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) !== 1) {
            throw new LogicException('Tenant identifiers must be lowercase UUID strings.');
        }
    }
}
