<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 組織與存取控制：品牌、營業時間、收件匣、團隊、角色/權限、座席容量。
 *
 * 權限模型（docs/spec/SPEC.md §07）：有效能力 = 已認證 + membership 有效 + 權限碼 + 資源 scope。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->string('name', 200);
            $table->string('slug', 100);
            $table->string('status', 20)->default('active');
            // 顯示設定：客服稱呼、logo、隱私文案、白名單 CSS token（不接受任意 CSS/JS/HTML）。
            $table->jsonb('settings')->default('{}');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
            $table->unique(['workspace_id', 'slug']);
        });
        TenantSchema::enum('brands', 'status', ['active', 'disabled']);
        TenantSchema::enableRls('brands');

        Schema::create('business_hours', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->string('name', 200);
            $table->string('timezone', 64);
            // {"mon":[["09:00","18:00"]], ...}；由 App\Modules\Workspaces\Support\BusinessHoursSchedule 驗證。
            $table->jsonb('weekly_schedule');
            // [{"date":"2026-10-10","name":"國慶日","closed":true}]
            $table->jsonb('holidays')->default('[]');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
        });
        TenantSchema::enableRls('business_hours');

        Schema::create('inboxes', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('brand_id');
            $table->string('name', 200);
            // 公開識別碼（不是秘密），高熵隨機字串，不可枚舉。
            $table->string('public_key', 64)->unique();
            $table->string('channel_type', 32)->default('web');
            $table->string('status', 20)->default('active');
            $table->string('ai_mode', 20)->default('disabled');
            $table->uuid('ai_profile_id')->nullable();
            $table->uuid('business_hours_id')->nullable();
            // 歡迎文案、離線文案、分派模式、CSAT、隱私說明等（JSON schema 驗證）。
            $table->jsonb('settings')->default('{}');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
            $table->unique(['workspace_id', 'brand_id', 'id']);
            TenantSchema::tenantForeign($table, 'brand_id', 'brands');
            TenantSchema::tenantForeign($table, 'business_hours_id', 'business_hours', 'set null');
        });
        TenantSchema::enum('inboxes', 'status', ['active', 'disabled']);
        TenantSchema::enum('inboxes', 'ai_mode', ['disabled', 'assist_only', 'auto_reply']);
        TenantSchema::enum('inboxes', 'channel_type', ['web', 'generic_api', 'line', 'telegram', 'email']);
        TenantSchema::enableRls('inboxes');

        // 允許嵌入 widget 的來源：正規化的 scheme://host[:port]，不接受 wildcard/regex。
        Schema::create('inbox_origins', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('inbox_id');
            $table->string('origin', 300);
            $table->timestampTz('created_at');
            $table->unique(['inbox_id', 'origin']);
            TenantSchema::tenantForeign($table, 'inbox_id', 'inboxes', 'cascade');
        });
        TenantSchema::check('inbox_origins', 'inbox_origins_origin_format', "origin ~ '^https?://[a-z0-9.-]+(:[0-9]{1,5})?$'");
        TenantSchema::enableRls('inbox_origins');

        Schema::create('teams', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->string('name', 200);
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
            $table->unique(['workspace_id', 'name']);
        });
        TenantSchema::enum('teams', 'status', ['active', 'disabled']);
        TenantSchema::enableRls('teams');

        Schema::create('team_memberships', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('team_id');
            $table->uuid('membership_id');
            $table->timestampTz('created_at');
            $table->unique(['team_id', 'membership_id']);
            TenantSchema::tenantForeign($table, 'team_id', 'teams', 'cascade');
            TenantSchema::tenantForeign($table, 'membership_id', 'workspace_memberships', 'cascade');
        });
        TenantSchema::enableRls('team_memberships');

        Schema::create('inbox_teams', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('inbox_id');
            $table->uuid('team_id');
            $table->timestampTz('created_at');
            $table->unique(['inbox_id', 'team_id']);
            TenantSchema::tenantForeign($table, 'inbox_id', 'inboxes', 'cascade');
            TenantSchema::tenantForeign($table, 'team_id', 'teams', 'cascade');
        });
        TenantSchema::enableRls('inbox_teams');

        // 座席可服務的收件匣（明確範圍）；仍需角色權限才能實際操作。
        Schema::create('inbox_memberships', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('inbox_id');
            $table->uuid('membership_id');
            $table->timestampTz('created_at');
            $table->unique(['inbox_id', 'membership_id']);
            TenantSchema::tenantForeign($table, 'inbox_id', 'inboxes', 'cascade');
            TenantSchema::tenantForeign($table, 'membership_id', 'workspace_memberships', 'cascade');
        });
        TenantSchema::enableRls('inbox_memberships');

        // 全域唯讀權限目錄（由 seeder 維護，對應 App\Modules\AccessControl\Permission enum）。
        Schema::create('permissions', function (Blueprint $table): void {
            $table->string('code', 64)->primary();
            $table->string('category', 32);
            $table->string('description', 300);
        });

        Schema::create('roles', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->string('key', 64);
            $table->string('name', 200);
            $table->string('description', 500)->nullable();
            // 系統模板角色（Owner 等）不可刪除，權限可由 Owner 調整。
            $table->boolean('is_system_template')->default(false);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
            $table->unique(['workspace_id', 'key']);
        });
        TenantSchema::enableRls('roles');

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->uuid('workspace_id');
            $table->uuid('role_id');
            $table->string('permission_code', 64);
            $table->primary(['role_id', 'permission_code']);
            TenantSchema::tenantForeign($table, 'role_id', 'roles', 'cascade');
            $table->foreign('permission_code')->references('code')->on('permissions')->restrictOnDelete();
        });
        TenantSchema::enableRls('role_permissions');

        // 角色授予：可限縮到 brand / inbox / team 範圍。多型 scope_id 由 service 驗證並有測試覆蓋。
        Schema::create('role_bindings', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('membership_id');
            $table->uuid('role_id');
            $table->string('scope_type', 16)->default('workspace');
            $table->uuid('scope_id')->nullable();
            $table->uuid('granted_by_membership_id')->nullable();
            $table->timestampTz('created_at');
            TenantSchema::tenantForeign($table, 'membership_id', 'workspace_memberships', 'cascade');
            TenantSchema::tenantForeign($table, 'role_id', 'roles', 'cascade');
            $table->index(['workspace_id', 'membership_id']);
        });
        DB::statement('CREATE UNIQUE INDEX role_bindings_unique ON role_bindings (membership_id, role_id, scope_type, scope_id) NULLS NOT DISTINCT');
        TenantSchema::enum('role_bindings', 'scope_type', ['workspace', 'brand', 'inbox', 'team']);
        TenantSchema::check('role_bindings', 'role_bindings_scope_shape', "(scope_type = 'workspace') = (scope_id IS NULL)");
        TenantSchema::enableRls('role_bindings');

        // 座席容量與 presence。分派交易會鎖定此列（鎖順序：conversation → capacity rows）。
        Schema::create('agent_capacity', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('membership_id');
            $table->unsignedInteger('max_active')->default(5);
            $table->string('presence_status', 16)->default('offline');
            $table->timestampTz('last_heartbeat_at')->nullable();
            $table->timestampTz('last_assigned_at')->nullable();
            // 離線寬限：超過後才重新分派其未讀待回覆案件。
            $table->timestampTz('offline_since')->nullable();
            $table->timestampTz('updated_at')->nullable();
            $table->unique(['workspace_id', 'membership_id']);
            TenantSchema::tenantForeign($table, 'membership_id', 'workspace_memberships', 'cascade');
        });
        TenantSchema::enum('agent_capacity', 'presence_status', ['available', 'busy', 'away', 'offline']);
        TenantSchema::enableRls('agent_capacity');

        // 稽核：append-only；before/after 只放非 secret 摘要。
        Schema::create('audit_logs', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->string('actor_type', 16);
            $table->uuid('actor_id')->nullable();
            $table->string('action', 100);
            $table->string('resource_type', 64)->nullable();
            $table->uuid('resource_id')->nullable();
            $table->jsonb('scope')->default('{}');
            $table->string('request_id', 64)->nullable();
            $table->string('ip_digest', 64)->nullable();
            $table->string('reason', 500)->nullable();
            $table->jsonb('before_safe')->nullable();
            $table->jsonb('after_safe')->nullable();
            $table->timestampTz('created_at');
            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'resource_type', 'resource_id']);
        });
        TenantSchema::enum('audit_logs', 'actor_type', ['staff', 'visitor', 'integration', 'system', 'platform']);
        TenantSchema::enableRls('audit_logs');
        TenantSchema::appendOnly('audit_logs');

        // HTTP 冪等紀錄：(workspace, principal, method, route, key) 唯一。
        Schema::create('idempotency_records', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->string('principal_scope', 128);
            $table->string('method', 8);
            $table->string('route_fingerprint', 200);
            $table->string('key', 128);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            // 回應內容；含短期 token 時以 envelope encryption 保存（見 IdempotencyStore）。
            $table->text('response_body')->nullable();
            $table->boolean('response_encrypted')->default(false);
            $table->timestampTz('created_at');
            $table->timestampTz('expires_at');
            $table->unique(['workspace_id', 'principal_scope', 'method', 'route_fingerprint', 'key'], 'idempotency_records_scope_unique');
            $table->index('expires_at');
        });
        TenantSchema::enableRls('idempotency_records');
    }

    public function down(): void
    {
        foreach (['idempotency_records', 'audit_logs', 'agent_capacity', 'role_bindings', 'role_permissions', 'roles',
            'permissions', 'inbox_memberships', 'inbox_teams', 'team_memberships', 'teams', 'inbox_origins',
            'inboxes', 'business_hours', 'brands'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
