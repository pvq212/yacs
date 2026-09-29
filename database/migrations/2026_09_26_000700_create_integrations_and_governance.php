<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 渠道、通用串接、Webhook 與資料治理（docs/spec/DATA_MODEL.md §7、§8）。
 *
 * 去重分三層，各自獨立（不能只做其中一層）：
 *  - inbound_events(connector, external_event_id)：入站 webhook 事件去重；
 *  - messages(source_scope, external_message_id)：訊息層去重；
 *  - channel_deliveries(message, connector)：出站投遞去重。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_connectors', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('brand_id');
            $table->uuid('inbox_id');
            $table->string('type', 32);
            $table->string('name', 100);
            // 入站路由用公開 key（不是秘密）；驗章 secret 另以加密保存。
            $table->string('public_key', 64)->unique();
            $table->text('secret_encrypted')->nullable();
            $table->string('key_id', 64)->nullable();
            // 出站：接收回覆的目的地（generic webhook 渠道）。
            $table->string('outbound_url', 1000)->nullable();
            $table->jsonb('capabilities')->default('{}');
            $table->jsonb('settings')->default('{}');
            $table->string('health', 16)->default('unknown');
            $table->string('circuit_state', 16)->default('closed');
            $table->timestampTz('last_success_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
            $table->foreign(['workspace_id', 'brand_id', 'inbox_id'])
                ->references(['workspace_id', 'brand_id', 'id'])->on('inboxes')->cascadeOnDelete();
            // MVP：connector 與 inbox 一對一。
            $table->unique(['inbox_id']);
        });
        TenantSchema::enum('channel_connectors', 'status', ['active', 'disabled']);
        TenantSchema::enum('channel_connectors', 'health', ['unknown', 'healthy', 'degraded', 'failing']);
        TenantSchema::enum('channel_connectors', 'circuit_state', ['closed', 'open', 'half_open']);
        TenantSchema::enableRls('channel_connectors');

        Schema::create('channel_threads', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('connector_id');
            $table->string('external_thread_id', 200);
            $table->uuid('contact_id');
            $table->uuid('conversation_id');
            $table->jsonb('metadata_safe')->default('{}');
            $table->timestampTz('created_at');
            TenantSchema::tenantForeign($table, 'connector_id', 'channel_connectors', 'cascade');
            TenantSchema::tenantForeign($table, 'contact_id', 'contacts');
            TenantSchema::tenantForeign($table, 'conversation_id', 'conversations');
            $table->unique(['connector_id', 'external_thread_id', 'conversation_id']);
            $table->index(['connector_id', 'external_thread_id', 'created_at']);
        });
        TenantSchema::enableRls('channel_threads');

        Schema::create('inbound_events', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('connector_id');
            $table->string('external_event_id', 200);
            $table->string('body_hash', 64);
            // 原始 payload 以 envelope encryption 短期保存（預設 7 天）。
            $table->text('raw_payload_encrypted')->nullable();
            $table->string('state', 16)->default('received');
            $table->timestampTz('occurred_at')->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->string('error_code', 64)->nullable();
            TenantSchema::tenantForeign($table, 'connector_id', 'channel_connectors', 'cascade');
            $table->unique(['connector_id', 'external_event_id']);
        });
        TenantSchema::enum('inbound_events', 'state', ['received', 'processed', 'failed', 'ignored']);
        TenantSchema::enableRls('inbound_events');

        Schema::create('channel_deliveries', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('message_id');
            $table->uuid('connector_id');
            $table->string('state', 16)->default('pending');
            $table->string('external_message_id', 200)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampsTz();
            TenantSchema::tenantForeign($table, 'message_id', 'messages');
            TenantSchema::tenantForeign($table, 'connector_id', 'channel_connectors', 'cascade');
            $table->unique(['message_id', 'connector_id']);
            $table->index(['state', 'next_attempt_at']);
        });
        TenantSchema::enum('channel_deliveries', 'state', ['pending', 'sending', 'sent', 'delivered', 'read', 'failed', 'unknown']);
        TenantSchema::enableRls('channel_deliveries');

        Schema::create('delivery_attempts', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('delivery_id');
            $table->unsignedSmallInteger('attempt_no');
            $table->string('request_id_safe', 200)->nullable();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('result_state', 16);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampTz('created_at');
            TenantSchema::tenantForeign($table, 'delivery_id', 'channel_deliveries', 'cascade');
            $table->unique(['delivery_id', 'attempt_no']);
        });
        TenantSchema::enableRls('delivery_attempts');

        Schema::create('api_clients', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->string('name', 100);
            // token 前綴供辨識（例如 yacs_live_ab12），hash 供驗證；明文只在建立時顯示一次。
            $table->string('token_prefix', 32);
            $table->string('token_hash', 64)->unique();
            $table->jsonb('scopes');
            $table->jsonb('brand_scope')->default('[]');
            $table->jsonb('inbox_scope')->default('[]');
            $table->jsonb('allowed_ips')->default('[]');
            // 此 client 的外部 contact 身分簽發者名稱（upsert contact 時使用）。
            $table->string('contact_issuer', 200)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->string('status', 16)->default('active');
            $table->uuid('created_by')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            TenantSchema::tenantForeign($table, 'created_by', 'workspace_memberships');
        });
        TenantSchema::enum('api_clients', 'status', ['active', 'revoked']);
        TenantSchema::enableRls('api_clients');

        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->string('name', 100);
            $table->string('url', 1000);
            $table->text('secret_encrypted');
            $table->string('key_id', 64);
            $table->jsonb('event_types');
            $table->jsonb('brand_scope')->default('[]');
            $table->jsonb('inbox_scope')->default('[]');
            $table->string('status', 16)->default('active');
            // 401/403 時自動暫停，等待管理員處理。
            $table->string('paused_reason', 64)->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
        });
        TenantSchema::enum('webhook_endpoints', 'status', ['active', 'paused', 'disabled']);
        TenantSchema::enableRls('webhook_endpoints');

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('endpoint_id');
            $table->uuid('event_id');
            $table->string('event_type', 64);
            // 投遞的 body（安全投影）；每次重送 body 語意不變，只換 timestamp/signature。
            $table->jsonb('payload');
            $table->string('state', 16)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('last_error', 64)->nullable();
            $table->timestampsTz();
            TenantSchema::tenantForeign($table, 'endpoint_id', 'webhook_endpoints', 'cascade');
            $table->unique(['endpoint_id', 'event_id']);
            $table->index(['state', 'next_attempt_at']);
        });
        TenantSchema::enum('webhook_deliveries', 'state', ['pending', 'delivering', 'succeeded', 'failed', 'paused']);
        TenantSchema::enableRls('webhook_deliveries');

        Schema::create('webhook_attempts', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('delivery_id');
            $table->unsignedSmallInteger('attempt_no');
            $table->timestampTz('signed_at');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('response_excerpt_safe', 512)->nullable();
            $table->boolean('manual')->default(false);
            $table->uuid('actor_id')->nullable();
            $table->timestampTz('created_at');
            TenantSchema::tenantForeign($table, 'delivery_id', 'webhook_deliveries', 'cascade');
            $table->unique(['delivery_id', 'attempt_no']);
        });
        TenantSchema::enableRls('webhook_attempts');

        // ------------------------------------------------------------------
        // 資料治理
        // ------------------------------------------------------------------
        Schema::create('export_jobs', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('requested_by');
            $table->string('kind', 32);
            // 建立當下的授權範圍快照；下載前仍會重查目前權限（OPS-009）。
            $table->jsonb('scope_snapshot');
            $table->jsonb('query');
            $table->uuid('task_id')->nullable();
            $table->uuid('file_id')->nullable();
            $table->string('state', 16)->default('pending');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            TenantSchema::tenantForeign($table, 'requested_by', 'workspace_memberships');
            TenantSchema::tenantForeign($table, 'file_id', 'files');
        });
        TenantSchema::enum('export_jobs', 'state', ['pending', 'running', 'ready', 'failed', 'expired']);
        TenantSchema::enableRls('export_jobs');

        Schema::create('data_erasure_requests', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->string('scope_type', 16);
            $table->uuid('scope_id');
            $table->string('reason', 500);
            $table->uuid('requested_by');
            $table->uuid('approved_by')->nullable();
            $table->string('state', 16)->default('requested');
            $table->jsonb('result_safe')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            TenantSchema::tenantForeign($table, 'requested_by', 'workspace_memberships');
            TenantSchema::tenantForeign($table, 'approved_by', 'workspace_memberships');
        });
        TenantSchema::enum('data_erasure_requests', 'scope_type', ['contact', 'conversation']);
        TenantSchema::enum('data_erasure_requests', 'state', ['requested', 'approved', 'running', 'completed', 'rejected', 'failed']);
        TenantSchema::enableRls('data_erasure_requests');

        // 刪除 tombstone：備份還原後重放，避免已刪除資料復活（OPS-006/009）。
        Schema::create('erasure_tombstones', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('request_id');
            $table->string('resource_type', 32);
            $table->uuid('resource_id');
            $table->timestampTz('erased_at');
            TenantSchema::tenantForeign($table, 'request_id', 'data_erasure_requests');
            $table->unique(['resource_type', 'resource_id']);
        });
        TenantSchema::enableRls('erasure_tombstones');
        TenantSchema::appendOnly('erasure_tombstones');

        // 通知偏好（Email 摘要、瀏覽器通知、聲音）。
        Schema::create('notification_preferences', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('membership_id');
            $table->jsonb('preferences')->default('{}');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
            TenantSchema::tenantForeign($table, 'membership_id', 'workspace_memberships', 'cascade');
            $table->unique(['membership_id']);
        });
        TenantSchema::enableRls('notification_preferences');

        // 簡單條件/動作自動化規則（typed JSON，不執行任意程式）。
        Schema::create('automation_rules', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->string('name', 200);
            $table->string('trigger', 32);
            $table->jsonb('conditions');
            $table->jsonb('actions');
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
        });
        TenantSchema::enum('automation_rules', 'trigger', ['conversation.created', 'message.visitor_created', 'conversation.handoff_requested']);
        TenantSchema::enum('automation_rules', 'status', ['active', 'disabled']);
        TenantSchema::enableRls('automation_rules');
    }

    public function down(): void
    {
        foreach (['automation_rules', 'notification_preferences', 'erasure_tombstones', 'data_erasure_requests', 'export_jobs',
            'webhook_attempts', 'webhook_deliveries', 'webhook_endpoints', 'api_clients', 'delivery_attempts',
            'channel_deliveries', 'inbound_events', 'channel_threads', 'channel_connectors'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
