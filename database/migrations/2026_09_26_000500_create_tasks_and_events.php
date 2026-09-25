<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 可靠任務與事件：async_tasks、outbox_events、realtime_events（docs/spec/DATA_MODEL.md §7）。
 *
 * PostgreSQL 是任務/事件的權威來源；Redis 佇列只負責喚醒 worker。
 * worker 以 CAS + lease token（fencing）認領任務，完成時必須比對 lease token，
 * 過期 lease 的 worker 即使晚回也無法覆寫結果（OPS-003）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('async_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // 系統層任務（例如保留期清理）可無 workspace；只能由 system 角色處理。
            $table->uuid('workspace_id')->nullable();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            $table->string('type', 64);
            $table->uuid('entity_id')->nullable();
            $table->string('dedupe_key', 200);
            $table->string('state', 16)->default('pending');
            $table->string('queue_name', 16);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            // 小型參數（< 2 KiB）；大型資料以 entity_id 回 DB 讀取。
            $table->jsonb('payload')->default('{}');
            $table->timestampTz('not_before')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('deadline_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->jsonb('result_safe')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->index(['state', 'not_before', 'lease_expires_at'], 'async_tasks_dispatch_idx');
            $table->index(['workspace_id', 'type', 'entity_id']);
        });
        DB::statement('CREATE UNIQUE INDEX async_tasks_dedupe_unique ON async_tasks (workspace_id, dedupe_key) NULLS NOT DISTINCT');
        TenantSchema::enum('async_tasks', 'state', ['pending', 'queued', 'running', 'succeeded', 'failed', 'cancelled']);
        TenantSchema::enum('async_tasks', 'queue_name', ['core', 'ai', 'kb', 'events', 'channels']);
        TenantSchema::check('async_tasks', 'async_tasks_lease_shape', "(state = 'running') = (lease_token IS NOT NULL)");
        TenantSchema::enableRls('async_tasks');

        // Transactional outbox：與業務資料同交易寫入，commit 後由 dispatcher 以 lease 取出投遞。
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            $table->string('event_type', 64);
            $table->string('aggregate_type', 32);
            $table->uuid('aggregate_id');
            $table->uuid('conversation_id')->nullable();
            // 最小化的安全 payload（對外 webhook 用）；不含 secret、token 或內部備註。
            $table->jsonb('payload_safe');
            $table->string('state', 16)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('dispatched_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->index(['state', 'occurred_at']);
            $table->index(['state', 'next_attempt_at']);
        });
        TenantSchema::enum('outbox_events', 'state', ['pending', 'dispatching', 'dispatched', 'failed']);
        TenantSchema::enableRls('outbox_events');

        // 對話的持久 realtime 事件：依受眾（public / staff）分開投影與各自 event_seq。
        // 重連時依 cursor 補資料；保留 7 天，過期回 410 CURSOR_EXPIRED。
        Schema::create('realtime_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('conversation_id');
            $table->string('audience', 8);
            $table->unsignedBigInteger('event_seq');
            $table->string('type', 64);
            $table->jsonb('payload');
            $table->timestampTz('created_at');
            // 推送狀態（Reverb 送出是 best effort；遺漏由 HTTP 補資料）。
            $table->timestampTz('published_at')->nullable();
            TenantSchema::tenantForeign($table, 'conversation_id', 'conversations');
            $table->unique(['conversation_id', 'audience', 'event_seq']);
            $table->index('created_at');
            $table->index(['published_at', 'created_at']);
        });
        TenantSchema::enum('realtime_events', 'audience', ['public', 'staff']);
        TenantSchema::enableRls('realtime_events');
    }

    public function down(): void
    {
        foreach (['realtime_events', 'outbox_events', 'async_tasks'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
