<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 對話、訊息與相關客服資料（docs/spec/DATA_MODEL.md §4），以及私有檔案（§8）。
 *
 * 關鍵不變量由資料庫約束保證，而不只是應用程式判斷：
 *  - status / handling_mode / assignee 的組合 CHECK；
 *  - message_seq、client_message_id、external_message_id、ai_run final message 唯一；
 *  - 所有關聯皆為 workspace-aware 複合外鍵。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('brand_id')->nullable();
            $table->string('owner_type', 16);
            $table->uuid('owner_id')->nullable();
            // 訪客上傳綁定 contact，用於下載/附加時的持有權檢查。
            $table->uuid('contact_id')->nullable();
            $table->string('purpose', 16);
            // object key 由伺服器產生（workspace/purpose/yyyy/mm/uuid），caller 無法指定。
            $table->string('object_key', 300)->unique();
            $table->string('original_name', 255);
            $table->string('declared_mime', 100);
            $table->string('detected_mime', 100)->nullable();
            $table->unsignedBigInteger('declared_bytes');
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();
            $table->string('scan_state', 20)->default('pending_upload');
            $table->string('rejection_code', 64)->nullable();
            $table->jsonb('scan_result_safe')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();
            TenantSchema::tenantForeign($table, 'brand_id', 'brands');
            TenantSchema::tenantForeign($table, 'contact_id', 'contacts');
            $table->index(['workspace_id', 'owner_type', 'owner_id']);
        });
        TenantSchema::enum('files', 'owner_type', ['visitor', 'staff', 'integration', 'system']);
        TenantSchema::enum('files', 'purpose', ['chat', 'knowledge', 'export']);
        TenantSchema::enum('files', 'scan_state', ['pending_upload', 'quarantined', 'clean', 'rejected']);
        TenantSchema::enableRls('files');

        Schema::create('conversations', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('brand_id');
            $table->uuid('inbox_id');
            $table->uuid('contact_id');
            $table->string('status', 20)->default('open');
            $table->string('handling_mode', 20);
            $table->uuid('assignee_id')->nullable();
            $table->uuid('team_id')->nullable();
            // 人工操作的樂觀鎖版本；與 answer_epoch（廢止舊 AI 答案）不可混用。
            $table->unsignedBigInteger('version')->default(0);
            $table->unsignedBigInteger('answer_epoch')->default(0);
            $table->unsignedBigInteger('next_message_seq')->default(1);
            $table->unsignedBigInteger('public_event_seq')->default(0);
            $table->unsignedBigInteger('staff_event_seq')->default(0);
            $table->uuid('latest_customer_message_id')->nullable();
            $table->timestampTz('last_customer_message_at')->nullable();
            $table->timestampTz('last_message_at')->nullable();
            // 會員最後一則訊息尚未被人工公開回覆的起算時間（逾時待處理與重新分派用）。
            $table->timestampTz('awaiting_reply_since')->nullable();
            $table->timestampTz('first_human_reply_at')->nullable();
            $table->timestampTz('first_ai_reply_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->string('resolution_code', 64)->nullable();
            $table->unsignedInteger('resolution_cycle')->default(1);
            $table->timestampTz('wake_at')->nullable();
            $table->uuid('related_conversation_id')->nullable();
            $table->unsignedSmallInteger('ai_clarification_count')->default(0);
            $table->string('subject', 300)->nullable();
            $table->string('channel_source', 16)->default('widget');
            $table->timestampsTz();
            $table->foreign(['workspace_id', 'brand_id', 'inbox_id'])
                ->references(['workspace_id', 'brand_id', 'id'])->on('inboxes')->restrictOnDelete();
            $table->foreign(['workspace_id', 'brand_id', 'contact_id'])
                ->references(['workspace_id', 'brand_id', 'id'])->on('contacts')->restrictOnDelete();
            TenantSchema::tenantForeign($table, 'assignee_id', 'workspace_memberships');
            TenantSchema::tenantForeign($table, 'team_id', 'teams', 'set null');
            TenantSchema::tenantForeign($table, 'related_conversation_id', 'conversations');
            $table->index(['workspace_id', 'inbox_id', 'status', 'last_message_at', 'id'], 'conversations_inbox_status_idx');
            $table->index(['workspace_id', 'assignee_id', 'status']);
            $table->index(['workspace_id', 'handling_mode', 'status', 'created_at'], 'conversations_mode_idx');
            $table->index(['workspace_id', 'contact_id', 'created_at']);
            $table->index(['status', 'wake_at']);
        });
        TenantSchema::enum('conversations', 'status', ['open', 'waiting_customer', 'snoozed', 'resolved']);
        TenantSchema::enum('conversations', 'handling_mode', ['ai', 'human_queue', 'human']);
        TenantSchema::enum('conversations', 'channel_source', ['widget', 'hosted', 'api', 'webhook', 'staff', 'line', 'telegram', 'email']);
        TenantSchema::check('conversations', 'conversations_state_shape', <<<'SQL'
            status = 'resolved'
            OR (handling_mode IN ('ai', 'human_queue') AND status = 'open' AND assignee_id IS NULL)
            OR (handling_mode = 'human' AND assignee_id IS NOT NULL)
        SQL);
        TenantSchema::check('conversations', 'conversations_snooze_shape', "(status = 'snoozed') = (wake_at IS NOT NULL)");
        TenantSchema::check('conversations', 'conversations_resolution_shape', "(status = 'resolved') = (resolved_at IS NOT NULL)");
        TenantSchema::check('conversations', 'conversations_seq_positive', 'next_message_seq >= 1 AND resolution_cycle >= 1');
        TenantSchema::enableRls('conversations');

        Schema::create('messages', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('conversation_id');
            $table->unsignedBigInteger('message_seq');
            $table->string('author_type', 16);
            $table->uuid('staff_membership_id')->nullable();
            $table->uuid('contact_id')->nullable();
            $table->uuid('ai_run_id')->nullable();
            $table->string('visibility', 16);
            $table->string('kind', 16);
            $table->text('body_text');
            // 客戶端產生的訊息 ID（重試沿用），在 source_scope 內長期唯一。
            $table->uuid('client_message_id')->nullable();
            // 例如 visitor-session:<id>、staff:<membership>、api:<client>、connector:<id>。
            $table->string('source_scope', 100);
            $table->string('external_message_id', 200)->nullable();
            // 引用訊息、citations、系統事件代碼等小型 metadata；不放授權或關聯主鍵。
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at');
            $table->timestampTz('redacted_at')->nullable();
            TenantSchema::tenantForeign($table, 'conversation_id', 'conversations');
            TenantSchema::tenantForeign($table, 'staff_membership_id', 'workspace_memberships');
            TenantSchema::tenantForeign($table, 'contact_id', 'contacts');
            $table->unique(['conversation_id', 'message_seq']);
        });
        DB::statement('CREATE UNIQUE INDEX messages_client_unique ON messages (workspace_id, source_scope, client_message_id) WHERE client_message_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX messages_external_unique ON messages (workspace_id, source_scope, external_message_id) WHERE external_message_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX messages_ai_run_unique ON messages (ai_run_id) WHERE ai_run_id IS NOT NULL');
        TenantSchema::enum('messages', 'author_type', ['visitor', 'staff', 'ai', 'system']);
        TenantSchema::enum('messages', 'visibility', ['public', 'internal']);
        TenantSchema::enum('messages', 'kind', ['text', 'attachment', 'system']);
        TenantSchema::check('messages', 'messages_author_shape', <<<'SQL'
            (author_type = 'staff' AND staff_membership_id IS NOT NULL)
            OR (author_type = 'visitor' AND contact_id IS NOT NULL AND visibility = 'public')
            OR (author_type = 'ai' AND visibility = 'public')
            OR (author_type = 'system')
        SQL);
        TenantSchema::check('messages', 'messages_seq_positive', 'message_seq >= 1');
        TenantSchema::enableRls('messages');

        // 循環參照：conversation 指向最新會員訊息。
        Schema::table('conversations', function (Blueprint $table): void {
            TenantSchema::tenantForeign($table, 'latest_customer_message_id', 'messages');
        });

        Schema::create('message_attachments', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('message_id');
            $table->uuid('file_id');
            $table->unsignedSmallInteger('position');
            TenantSchema::tenantForeign($table, 'message_id', 'messages', 'cascade');
            TenantSchema::tenantForeign($table, 'file_id', 'files');
            $table->unique(['message_id', 'position']);
            $table->unique(['message_id', 'file_id']);
        });
        TenantSchema::enableRls('message_attachments');

        Schema::create('message_redactions', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('message_id');
            $table->string('actor_type', 16);
            $table->uuid('actor_id')->nullable();
            $table->string('reason', 500);
            $table->timestampTz('created_at');
            TenantSchema::tenantForeign($table, 'message_id', 'messages');
        });
        TenantSchema::enableRls('message_redactions');
        TenantSchema::appendOnly('message_redactions');

        Schema::create('conversation_assignments', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('conversation_id');
            $table->uuid('from_assignee_id')->nullable();
            $table->uuid('to_assignee_id')->nullable();
            $table->uuid('team_id')->nullable();
            $table->string('reason', 64);
            $table->string('note', 500)->nullable();
            $table->string('actor_type', 16);
            $table->uuid('actor_id')->nullable();
            $table->boolean('forced')->default(false);
            $table->timestampTz('created_at');
            TenantSchema::tenantForeign($table, 'conversation_id', 'conversations');
            TenantSchema::tenantForeign($table, 'from_assignee_id', 'workspace_memberships');
            TenantSchema::tenantForeign($table, 'to_assignee_id', 'workspace_memberships');
            $table->index(['conversation_id', 'created_at']);
        });
        TenantSchema::enableRls('conversation_assignments');
        TenantSchema::appendOnly('conversation_assignments');

        Schema::create('conversation_reads', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('conversation_id');
            $table->string('reader_type', 16);
            $table->uuid('reader_id');
            $table->unsignedBigInteger('last_read_message_seq')->default(0);
            $table->timestampTz('read_at');
            TenantSchema::tenantForeign($table, 'conversation_id', 'conversations', 'cascade');
            $table->unique(['conversation_id', 'reader_type', 'reader_id']);
        });
        TenantSchema::enum('conversation_reads', 'reader_type', ['contact', 'membership']);
        TenantSchema::enableRls('conversation_reads');

        Schema::create('tags', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->string('name', 100);
            $table->string('color_token', 32)->default('neutral');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
            $table->unique(['workspace_id', 'name']);
        });
        TenantSchema::enableRls('tags');

        Schema::create('conversation_tags', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('conversation_id');
            $table->uuid('tag_id');
            $table->timestampTz('created_at');
            TenantSchema::tenantForeign($table, 'conversation_id', 'conversations', 'cascade');
            TenantSchema::tenantForeign($table, 'tag_id', 'tags', 'cascade');
            $table->unique(['conversation_id', 'tag_id']);
        });
        TenantSchema::enableRls('conversation_tags');

        // 快捷回覆巨集：只允許核准變數，渲染時 escaping，不執行任何程式。
        Schema::create('macros', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('brand_id')->nullable();
            $table->string('title', 200);
            $table->text('body_template');
            $table->jsonb('allowed_variables')->default('[]');
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
            TenantSchema::tenantForeign($table, 'brand_id', 'brands');
        });
        TenantSchema::enum('macros', 'status', ['active', 'disabled']);
        TenantSchema::enableRls('macros');

        // 結案原因（規格要求結案必選原因；可由運營管理）。
        Schema::create('resolution_reasons', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->string('code', 64);
            $table->string('label', 200);
            $table->string('status', 20)->default('active');
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
            $table->unique(['workspace_id', 'code']);
        });
        TenantSchema::enum('resolution_reasons', 'status', ['active', 'disabled']);
        TenantSchema::enableRls('resolution_reasons');

        Schema::create('csat_responses', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('conversation_id');
            $table->unsignedInteger('resolution_cycle');
            $table->unsignedSmallInteger('score');
            $table->text('comment')->nullable();
            $table->uuid('contact_id');
            $table->timestampTz('submitted_at');
            TenantSchema::tenantForeign($table, 'conversation_id', 'conversations');
            TenantSchema::tenantForeign($table, 'contact_id', 'contacts');
            $table->unique(['conversation_id', 'resolution_cycle']);
        });
        TenantSchema::check('csat_responses', 'csat_responses_score_range', 'score BETWEEN 1 AND 5');
        TenantSchema::enableRls('csat_responses');
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropForeign(['workspace_id', 'latest_customer_message_id']);
        });
        foreach (['csat_responses', 'resolution_reasons', 'macros', 'conversation_tags', 'tags', 'conversation_reads',
            'conversation_assignments', 'message_redactions', 'message_attachments', 'messages', 'conversations',
            'files'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
