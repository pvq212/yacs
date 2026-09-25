<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 客戶、身分與訪客 session（docs/spec/DATA_MODEL.md §3）。
 *
 * 與原規格的差異（docs/adr/0008-visitor-refresh-token-single-source.md）：
 * visitor_sessions 不再保存 refresh_hash，refresh token 只存在 visitor_refresh_tokens，
 * 以保留完整旋轉歷史並偵測舊 token 重用。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('brand_id');
            $table->string('name', 200)->nullable();
            $table->text('email_encrypted')->nullable();
            // 以伺服器金鑰計算的查詢 digest（HMAC），不是可暴力猜測的無鹽 hash。
            $table->string('email_lookup_digest', 64)->nullable();
            $table->text('phone_encrypted')->nullable();
            // 經驗證來源寫入的屬性與訪客可編輯屬性（依 customer_attributes 定義驗證）。
            $table->jsonb('attributes')->default('{}');
            $table->string('status', 20)->default('active');
            $table->timestampsTz();
            $table->unique(['workspace_id', 'brand_id', 'id']);
            $table->index(['workspace_id', 'brand_id', 'email_lookup_digest']);
            TenantSchema::tenantForeign($table, 'brand_id', 'brands');
        });
        TenantSchema::enum('contacts', 'status', ['active', 'blocked', 'erased']);
        TenantSchema::enableRls('contacts');

        // 宿主網站的身分簽發者（HS256 key）；secret 以 envelope encryption 保存。
        Schema::create('identity_issuers', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('brand_id');
            $table->string('name', 200);
            $table->string('issuer', 300);
            $table->string('key_id', 100);
            $table->text('secret_encrypted');
            // 可使用此 issuer 的 inbox 清單；空陣列代表該品牌所有 inbox。
            $table->jsonb('inbox_scope')->default('[]');
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
            $table->unique(['issuer', 'key_id']);
            TenantSchema::tenantForeign($table, 'brand_id', 'brands');
        });
        TenantSchema::enum('identity_issuers', 'status', ['active', 'disabled']);
        TenantSchema::enableRls('identity_issuers');

        Schema::create('contact_identities', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('brand_id');
            $table->uuid('contact_id');
            $table->string('issuer', 300);
            $table->string('subject', 300);
            $table->timestampTz('verified_at');
            $table->timestampTz('created_at');
            $table->unique(['workspace_id', 'brand_id', 'issuer', 'subject']);
            $table->foreign(['workspace_id', 'brand_id', 'contact_id'])
                ->references(['workspace_id', 'brand_id', 'id'])->on('contacts')->cascadeOnDelete();
        });
        TenantSchema::enableRls('contact_identities');

        Schema::create('visitor_sessions', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('brand_id');
            $table->uuid('inbox_id');
            $table->uuid('contact_id');
            $table->string('identity_level', 16);
            // 驗證 session 的 issuer/subject 快照（匿名為 null）。
            $table->uuid('identity_issuer_id')->nullable();
            $table->string('access_token_hash', 64)->unique();
            $table->timestampTz('access_expires_at');
            // refresh token（亦即匿名 resume token）的閒置到期時間；token 本體在 visitor_refresh_tokens。
            $table->timestampTz('refresh_expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_reason', 64)->nullable();
            $table->unsignedBigInteger('session_generation')->default(0);
            $table->jsonb('context')->default('{}');
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['workspace_id', 'brand_id', 'inbox_id'])
                ->references(['workspace_id', 'brand_id', 'id'])->on('inboxes')->restrictOnDelete();
            $table->foreign(['workspace_id', 'brand_id', 'contact_id'])
                ->references(['workspace_id', 'brand_id', 'id'])->on('contacts')->restrictOnDelete();
            TenantSchema::tenantForeign($table, 'identity_issuer_id', 'identity_issuers');
            $table->index(['workspace_id', 'contact_id']);
        });
        TenantSchema::enum('visitor_sessions', 'identity_level', ['anonymous', 'verified']);
        TenantSchema::enableRls('visitor_sessions');

        // 旋轉式 refresh token：每次使用產生新 token，舊 token 標記 used；重用舊 token = 撤銷整個 family。
        Schema::create('visitor_refresh_tokens', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('session_id');
            $table->uuid('family_id');
            $table->string('token_hash', 64)->unique();
            $table->uuid('replaced_by')->nullable();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at');
            TenantSchema::tenantForeign($table, 'session_id', 'visitor_sessions', 'cascade');
            $table->index(['session_id', 'family_id']);
        });
        TenantSchema::enableRls('visitor_refresh_tokens');

        // 一次性 JWT 交換紀錄：(issuer, jti) 唯一，原子 consume 防 replay。
        Schema::create('identity_exchanges', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('issuer_id');
            $table->string('jti_hash', 64);
            $table->string('request_hash', 64);
            $table->uuid('session_id')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at');
            $table->unique(['issuer_id', 'jti_hash']);
            TenantSchema::tenantForeign($table, 'issuer_id', 'identity_issuers', 'cascade');
            TenantSchema::tenantForeign($table, 'session_id', 'visitor_sessions', 'set null');
            $table->index('expires_at');
        });
        TenantSchema::enableRls('identity_exchanges');

        // 客戶屬性定義：可信等級固定，verified/VIP/餘額等不可由訪客修改。
        Schema::create('customer_attributes', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->string('key', 64);
            $table->string('label', 200);
            $table->string('type', 16);
            $table->string('visibility', 16)->default('staff');
            $table->boolean('editable_by_visitor')->default(false);
            $table->jsonb('validation_rules')->default('{}');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
            $table->unique(['workspace_id', 'key']);
        });
        TenantSchema::enum('customer_attributes', 'type', ['string', 'number', 'boolean', 'date']);
        TenantSchema::enum('customer_attributes', 'visibility', ['staff', 'sensitive']);
        TenantSchema::check('customer_attributes', 'customer_attributes_key_format', "key ~ '^[a-z][a-z0-9_]{0,63}$'");
        TenantSchema::enableRls('customer_attributes');

        Schema::create('contact_merge_events', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('brand_id');
            $table->uuid('source_contact_id');
            $table->uuid('target_contact_id');
            $table->uuid('conversation_id');
            $table->string('actor_type', 16);
            $table->uuid('actor_id')->nullable();
            $table->string('reason', 500);
            $table->timestampTz('created_at');
            $table->foreign(['workspace_id', 'brand_id', 'source_contact_id'])
                ->references(['workspace_id', 'brand_id', 'id'])->on('contacts');
            $table->foreign(['workspace_id', 'brand_id', 'target_contact_id'])
                ->references(['workspace_id', 'brand_id', 'id'])->on('contacts');
        });
        TenantSchema::enableRls('contact_merge_events');
        TenantSchema::appendOnly('contact_merge_events');
    }

    public function down(): void
    {
        foreach (['contact_merge_events', 'customer_attributes', 'identity_exchanges', 'visitor_refresh_tokens',
            'visitor_sessions', 'contact_identities', 'identity_issuers', 'contacts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
