<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 平台基礎：RLS 輔助函式、全域使用者、workspace、成員資格與 staff session。
 *
 * 依 docs/spec/DATA_MODEL.md §2、§10 的 migration 順序建立。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 目前 session 的 workspace；未設定或空字串時為 NULL（RLS 因此 fail closed）。
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION yacs_current_workspace_id() RETURNS uuid
            LANGUAGE sql STABLE PARALLEL SAFE AS $$
                SELECT NULLIF(current_setting('yacs.workspace_id', true), '')::uuid
            $$
        SQL);

        // 目前 session 的 staff user（僅用於「列出我所屬的 workspace」這類跨 workspace 讀取）。
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION yacs_current_user_id() RETURNS uuid
            LANGUAGE sql STABLE PARALLEL SAFE AS $$
                SELECT NULLIF(current_setting('yacs.user_id', true), '')::uuid
            $$
        SQL);

        // append-only 表的保護：UPDATE 一律拒絕；DELETE 只允許保留期清理程序。
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION yacs_forbid_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND current_setting('yacs.purge', true) = 'on' THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'table % is append-only (%)', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END
            $$
        SQL);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // 正規化（小寫、去空白）後的 email，作為全域唯一登入識別。
            $table->string('email_normalized', 320)->unique();
            $table->string('email', 320);
            $table->string('name', 200);
            $table->string('password_hash', 255)->nullable();
            // TOTP secret 以 envelope encryption 儲存，永不回傳 API。
            $table->text('mfa_secret_encrypted')->nullable();
            $table->timestampTz('mfa_enabled_at')->nullable();
            $table->text('mfa_recovery_codes_encrypted')->nullable();
            // 最近一次成功使用的 TOTP 時間步，防止同一組驗證碼重放。
            $table->unsignedBigInteger('mfa_last_timestep')->nullable();
            $table->string('status', 20)->default('active');
            // 平台維運者：可看系統健康、批准內網 egress；不代表任何 workspace 權限。
            $table->boolean('is_platform_operator')->default(false);
            $table->string('locale', 10)->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
        });
        TenantSchema::enum('users', 'status', ['active', 'disabled']);

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email_normalized', 320)->primary();
            $table->string('token_hash', 128);
            $table->timestampTz('created_at');
        });

        // Laravel 原生 database session driver 格式（payload 由框架加密）。
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        // 每個 staff 登入 session 的安全紀錄：MFA 狀態、撤銷與 realtime fanout 識別。
        // 不使用 session cookie 原文作為 channel 名稱，而是獨立 UUID。
        Schema::create('staff_session_security', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('session_id_digest', 64)->unique();
            $table->unsignedBigInteger('session_generation')->default(0);
            $table->timestampTz('mfa_verified_at')->nullable();
            $table->string('ip_digest', 64)->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('workspaces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 200);
            $table->string('slug', 100)->unique();
            $table->string('timezone', 64)->default('Asia/Taipei');
            $table->string('default_locale', 10)->default('zh_TW');
            $table->string('status', 20)->default('active');
            $table->jsonb('settings')->default('{}');
            // 知識發布/撤下時遞增，AI 發布前比對以避免引用已撤下內容。
            $table->unsignedBigInteger('knowledge_generation')->default(0);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
        });
        TenantSchema::enum('workspaces', 'status', ['active', 'suspended']);

        Schema::create('workspace_memberships', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->string('display_name', 200);
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
            $table->unique(['workspace_id', 'user_id']);
            $table->index('user_id');
        });
        TenantSchema::enum('workspace_memberships', 'status', ['invited', 'active', 'disabled']);

        // RLS：workspace 列可被「目前 workspace」或「目前使用者所屬」看見。
        DB::statement('ALTER TABLE workspaces ENABLE ROW LEVEL SECURITY');
        TenantSchema::addPolicy('workspaces', 'yacs_workspace_current', 'id = yacs_current_workspace_id()', 'id = yacs_current_workspace_id()');
        TenantSchema::addPolicy(
            'workspaces',
            'yacs_workspace_member',
            'EXISTS (SELECT 1 FROM workspace_memberships m WHERE m.workspace_id = workspaces.id AND m.user_id = yacs_current_user_id())',
            null,
            'SELECT',
        );

        TenantSchema::enableRls('workspace_memberships');
        TenantSchema::addPolicy('workspace_memberships', 'yacs_membership_self', 'user_id = yacs_current_user_id()', null, 'SELECT');

        // 邀請：token 只存 hash，單次使用。
        Schema::create('staff_invitations', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->string('email_normalized', 320);
            $table->string('token_hash', 64)->unique();
            $table->uuid('membership_id');
            $table->uuid('invited_by_membership_id')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at');
            TenantSchema::tenantForeign($table, 'membership_id', 'workspace_memberships', 'cascade');
            TenantSchema::tenantForeign($table, 'invited_by_membership_id', 'workspace_memberships');
        });
        TenantSchema::enableRls('staff_invitations');

        // 框架需要的全域表（Redis 佇列失敗紀錄、batch）。
        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestampTz('failed_at')->useCurrent();
        });

        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['job_batches', 'failed_jobs', 'staff_invitations', 'workspace_memberships', 'workspaces',
            'staff_session_security', 'sessions', 'password_reset_tokens', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS yacs_forbid_mutation()');
        DB::statement('DROP FUNCTION IF EXISTS yacs_current_user_id()');
        DB::statement('DROP FUNCTION IF EXISTS yacs_current_workspace_id()');
    }
};
