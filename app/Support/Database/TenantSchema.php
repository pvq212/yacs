<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Migration 共用工具：租戶表欄位、Row Level Security、append-only 保護與 CHECK 約束。
 *
 * 設計依據 docs/spec/DATA_MODEL.md §1：
 *  - 租戶表 PRIMARY KEY(id) + UNIQUE(workspace_id, id)，子表以複合外鍵指向父表的
 *    (workspace_id, id)，從資料庫層防止跨 workspace 關聯。
 *  - RLS 是第二層防護（第一層為 Laravel Policy 與查詢 scope），policy 只比對
 *    `yacs.workspace_id` 這個 session 設定；見 docs/adr/0004-postgres-rls-roles.md。
 *
 * 只能在 migration 中使用；執行期程式不得呼叫 DDL。
 */
final class TenantSchema
{
    /**
     * 建立租戶表的標準欄位：UUID 主鍵、workspace_id 外鍵、(workspace_id, id) 唯一鍵。
     */
    public static function tenantKeys(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->uuid('workspace_id');
        $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
        $table->unique(['workspace_id', 'id']);
    }

    /**
     * 建立指向同 workspace 父表的複合外鍵：(workspace_id, $column) → $parent(workspace_id, id)。
     *
     * 欄位可為 null；PostgreSQL 預設 MATCH SIMPLE，任一欄為 null 時不檢查。
     */
    public static function tenantForeign(
        Blueprint $table,
        string $column,
        string $parent,
        string $onDelete = 'restrict',
    ): void {
        $foreign = $table->foreign(['workspace_id', $column])
            ->references(['workspace_id', 'id'])
            ->on($parent);

        match ($onDelete) {
            'cascade' => $foreign->cascadeOnDelete(),
            'set null' => $foreign->nullOnDelete(),
            default => $foreign->restrictOnDelete(),
        };
    }

    /**
     * 啟用 Row Level Security，只允許存取目前 session 設定的 workspace。
     *
     * 未設定 workspace 時 policy 比對為 null → 看不到任何列、也寫不進任何列（fail closed）。
     * 表擁有者（migration 角色）不受 RLS 限制；runtime 角色必須經 SET ROLE yacs_system
     * 才能跨 workspace，且只允許在 TenantDatabase::asSystem() 內使用。
     */
    public static function enableRls(string $table, string $column = 'workspace_id'): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement(
            "CREATE POLICY yacs_tenant_isolation ON {$table} "
            ."USING ({$column} = yacs_current_workspace_id()) "
            ."WITH CHECK ({$column} = yacs_current_workspace_id())"
        );
    }

    /**
     * 附加一條額外的 permissive policy（多條 policy 之間為 OR）。
     */
    public static function addPolicy(string $table, string $name, string $using, ?string $check = null, string $command = 'ALL'): void
    {
        $sql = "CREATE POLICY {$name} ON {$table} FOR {$command} USING ({$using})";
        if ($check !== null) {
            $sql .= " WITH CHECK ({$check})";
        }
        DB::statement($sql);
    }

    /**
     * 讓表成為 append-only：禁止 UPDATE；DELETE 只允許在受控的保留期清理程序中
     * （同一交易內 `SET LOCAL yacs.purge = 'on'`）。
     */
    public static function appendOnly(string $table): void
    {
        DB::statement(
            "CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} "
            .'FOR EACH ROW EXECUTE FUNCTION yacs_forbid_mutation()'
        );
    }

    /**
     * 新增具名 CHECK 約束。
     */
    public static function check(string $table, string $name, string $expression): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
    }

    /**
     * 以 IN 清單建立 enum 型 CHECK（狀態欄位採 varchar + CHECK 以利演進）。
     *
     * @param  list<string>  $values
     */
    public static function enum(string $table, string $column, array $values, bool $nullable = false): void
    {
        $list = implode(',', array_map(static fn (string $v): string => "'".str_replace("'", "''", $v)."'", $values));
        $expr = "{$column} IN ({$list})";
        if ($nullable) {
            $expr = "{$column} IS NULL OR {$expr}";
        }
        self::check($table, "{$table}_{$column}_check", $expr);
    }
}
