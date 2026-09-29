<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI 設定、執行紀錄與知識庫/向量（docs/spec/DATA_MODEL.md §5、§6）。
 *
 * 向量策略（「同一邏輯表 + immutable embedding profile + 不固定維度 vector」）：
 *  - knowledge_embeddings.embedding 為不帶維度的 `vector`；以 CHECK(vector_dims = dimensions)
 *    與 (workspace_id, embedding_profile_id, dimensions) 複合外鍵確保與 profile 一致。
 *  - 查詢永遠只帶單一 profile；不同模型即使同維度也不混搜（RAG-005）。
 *  - ANN 索引由維運指令 `yacs:knowledge:ann-index` 依 profile 建立 partial expression index。
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // AI 供應商與模型
        // ------------------------------------------------------------------
        Schema::create('provider_connections', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->string('name', 100);
            // vendor 描述來源（openai / anthropic / google / private_gateway / ollama ...），
            // protocol 決定實際使用的 API 格式；兩者分開（AI_ADAPTERS §3）。
            $table->string('vendor', 32);
            $table->string('protocol', 32);
            $table->string('base_url', 500);
            $table->string('api_version', 32)->nullable();
            $table->text('secret_encrypted')->nullable();
            // 只允許白名單 header（不可覆蓋 Host/Authorization 等）；值不含 secret。
            $table->jsonb('headers_safe')->default('{}');
            $table->string('status', 16)->default('active');
            $table->string('health', 16)->default('unknown');
            $table->timestampTz('last_probe_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->unsignedBigInteger('configuration_version')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
            $table->unique(['workspace_id', 'name']);
        });
        TenantSchema::enum('provider_connections', 'protocol', ['openai_responses', 'openai_chat_completions', 'anthropic_messages', 'gemini_generate_content']);
        TenantSchema::enum('provider_connections', 'status', ['active', 'disabled']);
        TenantSchema::enum('provider_connections', 'health', ['unknown', 'healthy', 'degraded', 'failing']);
        TenantSchema::enableRls('provider_connections');

        Schema::create('ai_models', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('connection_id');
            $table->string('external_model_id', 200);
            $table->string('display_name', 200)->nullable();
            // 能力是「驗證結果」而非猜測：{capability: verified|declared|unsupported}
            $table->jsonb('capabilities')->default('{}');
            $table->unsignedInteger('context_limit')->nullable();
            $table->unsignedInteger('output_limit')->nullable();
            $table->string('verification_state', 16)->default('unverified');
            $table->timestampTz('verified_at')->nullable();
            // 價格設定（每百萬 token，含幣別與生效日）；不硬編碼網路價格。
            $table->jsonb('pricing')->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
            TenantSchema::tenantForeign($table, 'connection_id', 'provider_connections', 'cascade');
            $table->unique(['connection_id', 'external_model_id']);
        });
        TenantSchema::enum('ai_models', 'verification_state', ['unverified', 'verified', 'failed']);
        TenantSchema::enum('ai_models', 'status', ['active', 'disabled']);
        TenantSchema::enableRls('ai_models');

        // Embedding profile 建立後不可修改（模型/維度/正規化/task type 任一改變都要新 profile 並全量重建）。
        Schema::create('embedding_profiles', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->string('name', 100);
            $table->uuid('connection_id');
            $table->uuid('model_id');
            $table->unsignedInteger('dimensions');
            $table->string('metric', 16)->default('cosine');
            $table->string('normalization', 16)->default('l2');
            $table->string('task_type', 32)->nullable();
            $table->string('query_task_type', 32)->nullable();
            $table->unsignedBigInteger('configuration_version')->default(1);
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
            TenantSchema::tenantForeign($table, 'connection_id', 'provider_connections');
            TenantSchema::tenantForeign($table, 'model_id', 'ai_models');
            $table->unique(['workspace_id', 'id', 'dimensions']);
        });
        TenantSchema::check('embedding_profiles', 'embedding_profiles_dimensions_range', 'dimensions BETWEEN 1 AND 16000');
        TenantSchema::enum('embedding_profiles', 'metric', ['cosine', 'l2', 'inner_product']);
        TenantSchema::enum('embedding_profiles', 'normalization', ['none', 'l2']);
        TenantSchema::enum('embedding_profiles', 'status', ['active', 'retired']);
        TenantSchema::enableRls('embedding_profiles');

        Schema::create('ai_profiles', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->string('name', 100);
            $table->uuid('chat_model_id')->nullable();
            $table->uuid('embedding_profile_id')->nullable();
            $table->uuid('rerank_model_id')->nullable();
            $table->string('prompt_version', 32)->default('v1');
            // 模型參數（temperature 等）；只送 capability 允許的參數。
            $table->jsonb('parameters')->default('{}');
            // 逾時、token 上限、工具次數等（受平台上下限約束）。
            $table->jsonb('limits')->default('{}');
            // {"allowed_model_ids": [...], "cross_provider": false}
            $table->jsonb('fallback_policy')->default('{}');
            // {"allowed_destinations": [...], "redact_fields": [...]}
            $table->jsonb('data_policy')->default('{}');
            $table->jsonb('tool_keys')->default('[]');
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('configuration_version')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
            TenantSchema::tenantForeign($table, 'chat_model_id', 'ai_models');
            TenantSchema::tenantForeign($table, 'embedding_profile_id', 'embedding_profiles');
            TenantSchema::tenantForeign($table, 'rerank_model_id', 'ai_models');
            $table->unique(['workspace_id', 'name']);
        });
        TenantSchema::enum('ai_profiles', 'status', ['active', 'disabled']);
        TenantSchema::enableRls('ai_profiles');

        Schema::table('inboxes', function (Blueprint $table): void {
            TenantSchema::tenantForeign($table, 'ai_profile_id', 'ai_profiles', 'set null');
        });

        // ------------------------------------------------------------------
        // 知識庫
        // ------------------------------------------------------------------
        Schema::create('knowledge_bases', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('brand_id');
            $table->string('name', 200);
            $table->string('default_locale', 10)->default('zh_TW');
            $table->string('status', 16)->default('active');
            $table->uuid('active_embedding_profile_id')->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
            TenantSchema::tenantForeign($table, 'brand_id', 'brands');
            TenantSchema::tenantForeign($table, 'active_embedding_profile_id', 'embedding_profiles');
            $table->unique(['workspace_id', 'brand_id', 'id']);
        });
        TenantSchema::enum('knowledge_bases', 'status', ['active', 'disabled']);
        TenantSchema::enableRls('knowledge_bases');

        Schema::create('inbox_knowledge_bases', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('inbox_id');
            $table->uuid('knowledge_base_id');
            $table->unsignedSmallInteger('priority')->default(0);
            TenantSchema::tenantForeign($table, 'inbox_id', 'inboxes', 'cascade');
            TenantSchema::tenantForeign($table, 'knowledge_base_id', 'knowledge_bases', 'cascade');
            $table->unique(['inbox_id', 'knowledge_base_id']);
        });
        TenantSchema::enableRls('inbox_knowledge_bases');

        Schema::create('knowledge_documents', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('knowledge_base_id');
            $table->string('title', 300);
            $table->string('source_type', 16);
            $table->string('source_ref', 1000)->nullable();
            $table->string('external_key', 200)->nullable();
            $table->uuid('published_version_id')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestampsTz();
            TenantSchema::tenantForeign($table, 'knowledge_base_id', 'knowledge_bases', 'cascade');
            $table->index(['workspace_id', 'knowledge_base_id', 'status']);
        });
        DB::statement('CREATE UNIQUE INDEX knowledge_documents_external_key_unique ON knowledge_documents (knowledge_base_id, external_key) WHERE external_key IS NOT NULL');
        TenantSchema::enum('knowledge_documents', 'source_type', ['faq', 'markdown', 'text', 'pdf', 'url']);
        TenantSchema::enum('knowledge_documents', 'status', ['draft', 'published', 'unpublished', 'archived']);
        TenantSchema::enableRls('knowledge_documents');

        // 版本不可變（published 後內容不改）：編輯產生新版本。
        Schema::create('knowledge_versions', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('document_id');
            $table->unsignedInteger('version_number');
            $table->string('state', 16)->default('draft');
            $table->string('visibility', 24)->default('staff_only');
            $table->string('locale', 10)->default('zh_TW');
            $table->string('title', 300);
            // FAQ：question/answer/aliases；其他類型：body_text。
            $table->text('body_text')->nullable();
            $table->jsonb('faq')->nullable();
            $table->uuid('source_file_id')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_to')->nullable();
            // 對外 Help Center 公開文章（與 external_answerable 不同）。
            $table->boolean('public_article')->default(false);
            $table->string('public_url', 1000)->nullable();
            // 限定可用的收件匣（空陣列 = 綁定此知識庫的所有收件匣）。
            $table->jsonb('inbox_scope')->default('[]');
            $table->string('failure_code', 64)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            TenantSchema::tenantForeign($table, 'document_id', 'knowledge_documents', 'cascade');
            TenantSchema::tenantForeign($table, 'source_file_id', 'files');
            TenantSchema::tenantForeign($table, 'created_by', 'workspace_memberships');
            TenantSchema::tenantForeign($table, 'approved_by', 'workspace_memberships');
            $table->unique(['document_id', 'version_number']);
        });
        TenantSchema::enum('knowledge_versions', 'state', ['draft', 'indexing', 'ready', 'published', 'archived', 'failed']);
        TenantSchema::enum('knowledge_versions', 'visibility', ['external_answerable', 'staff_only']);
        TenantSchema::enableRls('knowledge_versions');

        Schema::table('knowledge_documents', function (Blueprint $table): void {
            TenantSchema::tenantForeign($table, 'published_version_id', 'knowledge_versions');
        });

        Schema::create('knowledge_chunks', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('version_id');
            $table->unsignedInteger('chunk_index');
            $table->jsonb('title_path')->default('[]');
            $table->text('text');
            // 定位：{"page": 3, "offset": 1200} 或 {"heading": "..."}
            $table->jsonb('source_locator')->default('{}');
            $table->string('content_hash', 64);
            $table->unsignedInteger('token_count');
            // lexical 檢索用文字（標題路徑 + 內文），建 PGroonga 索引。
            $table->text('search_text');
            $table->timestampTz('created_at');
            TenantSchema::tenantForeign($table, 'version_id', 'knowledge_versions', 'cascade');
            $table->unique(['version_id', 'chunk_index']);
        });
        DB::statement('CREATE INDEX knowledge_chunks_search_pgroonga ON knowledge_chunks USING pgroonga (search_text)');
        TenantSchema::enableRls('knowledge_chunks');

        Schema::create('knowledge_keywords', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('version_id');
            $table->string('keyword_normalized', 200);
            $table->string('kind', 16);
            TenantSchema::tenantForeign($table, 'version_id', 'knowledge_versions', 'cascade');
            $table->unique(['version_id', 'keyword_normalized', 'kind']);
            $table->index(['workspace_id', 'keyword_normalized']);
        });
        DB::statement('CREATE INDEX knowledge_keywords_trgm ON knowledge_keywords USING gin (keyword_normalized gin_trgm_ops)');
        TenantSchema::enum('knowledge_keywords', 'kind', ['alias', 'code', 'tag']);
        TenantSchema::enableRls('knowledge_keywords');

        Schema::create('knowledge_embeddings', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('chunk_id');
            $table->uuid('embedding_profile_id');
            $table->unsignedInteger('dimensions');
            $table->timestampTz('created_at');
            TenantSchema::tenantForeign($table, 'chunk_id', 'knowledge_chunks', 'cascade');
            $table->foreign(['workspace_id', 'embedding_profile_id', 'dimensions'])
                ->references(['workspace_id', 'id', 'dimensions'])->on('embedding_profiles')->restrictOnDelete();
            $table->unique(['chunk_id', 'embedding_profile_id']);
            $table->index(['embedding_profile_id', 'chunk_id']);
        });
        DB::statement('ALTER TABLE knowledge_embeddings ADD COLUMN embedding vector NOT NULL');
        TenantSchema::check('knowledge_embeddings', 'knowledge_embeddings_dims_match', 'vector_dims(embedding) = dimensions');
        TenantSchema::enableRls('knowledge_embeddings');

        Schema::create('knowledge_index_builds', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('knowledge_base_id');
            $table->uuid('profile_id');
            $table->string('state', 16)->default('pending');
            $table->unsignedInteger('total_chunks')->default(0);
            $table->unsignedInteger('indexed_chunks')->default(0);
            $table->unsignedInteger('failed_chunks')->default(0);
            $table->uuid('task_id')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestampsTz();
            TenantSchema::tenantForeign($table, 'knowledge_base_id', 'knowledge_bases', 'cascade');
            TenantSchema::tenantForeign($table, 'profile_id', 'embedding_profiles');
        });
        TenantSchema::enum('knowledge_index_builds', 'state', ['pending', 'running', 'completed', 'failed', 'activated', 'cancelled']);
        TenantSchema::enableRls('knowledge_index_builds');

        Schema::create('knowledge_evaluations', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('knowledge_base_id');
            $table->string('dataset_version', 32)->default('v1');
            $table->text('question');
            $table->text('expected_answer')->nullable();
            $table->jsonb('expected_source_ids')->default('[]');
            $table->string('expected_action', 16);
            $table->string('classification', 32);
            $table->uuid('approved_by')->nullable();
            $table->timestampsTz();
            TenantSchema::tenantForeign($table, 'knowledge_base_id', 'knowledge_bases', 'cascade');
            TenantSchema::tenantForeign($table, 'approved_by', 'workspace_memberships');
        });
        TenantSchema::enum('knowledge_evaluations', 'expected_action', ['answer', 'clarify', 'handoff']);
        TenantSchema::enableRls('knowledge_evaluations');

        Schema::create('knowledge_evaluation_runs', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('knowledge_base_id');
            $table->string('dataset_version', 32);
            $table->string('mode', 16);
            $table->jsonb('profile_snapshot');
            $table->jsonb('results');
            $table->jsonb('summary')->default('{}');
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at');
            TenantSchema::tenantForeign($table, 'knowledge_base_id', 'knowledge_bases', 'cascade');
        });
        TenantSchema::enum('knowledge_evaluation_runs', 'mode', ['vector', 'lexical', 'hybrid']);
        TenantSchema::enableRls('knowledge_evaluation_runs');

        // ------------------------------------------------------------------
        // AI 執行紀錄
        // ------------------------------------------------------------------
        Schema::create('ai_runs', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('conversation_id');
            $table->string('kind', 16);
            // assist 草稿的請求 id（每次請求去重）；autopilot 為 null。
            $table->string('request_key', 100)->nullable();
            $table->unsignedBigInteger('answer_epoch');
            $table->uuid('trigger_message_id')->nullable();
            $table->unsignedBigInteger('knowledge_generation');
            $table->unsignedBigInteger('configuration_version');
            $table->uuid('ai_profile_id')->nullable();
            $table->jsonb('profile_snapshot')->default('{}');
            $table->string('state', 16)->default('queued');
            $table->uuid('task_id')->nullable();
            $table->timestampTz('deadline_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->uuid('final_message_id')->nullable();
            // assist 草稿文字（只給 staff；不是公開訊息）。
            $table->text('draft_text')->nullable();
            $table->string('result_action', 16)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('handoff_reason', 64)->nullable();
            $table->uuid('requested_by')->nullable();
            $table->timestampsTz();
            TenantSchema::tenantForeign($table, 'conversation_id', 'conversations');
            TenantSchema::tenantForeign($table, 'trigger_message_id', 'messages');
            TenantSchema::tenantForeign($table, 'final_message_id', 'messages');
            TenantSchema::tenantForeign($table, 'ai_profile_id', 'ai_profiles', 'set null');
            TenantSchema::tenantForeign($table, 'requested_by', 'workspace_memberships');
            $table->index(['state', 'deadline_at']);
            $table->index(['conversation_id', 'created_at']);
        });
        // 每個對話同一 epoch 最多一個 autopilot run；assist 以 request_key 去重。
        DB::statement("CREATE UNIQUE INDEX ai_runs_autopilot_unique ON ai_runs (conversation_id, answer_epoch) WHERE kind = 'autopilot'");
        DB::statement("CREATE UNIQUE INDEX ai_runs_assist_request_unique ON ai_runs (conversation_id, request_key) WHERE kind <> 'autopilot'");
        DB::statement('CREATE UNIQUE INDEX ai_runs_final_message_unique ON ai_runs (final_message_id) WHERE final_message_id IS NOT NULL');
        TenantSchema::enum('ai_runs', 'kind', ['autopilot', 'assist_draft', 'assist_summary']);
        TenantSchema::enum('ai_runs', 'state', ['queued', 'running', 'published', 'drafted', 'stale', 'cancelled', 'failed', 'handed_off']);
        TenantSchema::check('ai_runs', 'ai_runs_request_key_shape', "(kind = 'autopilot') = (request_key IS NULL)");
        TenantSchema::enableRls('ai_runs');

        Schema::table('messages', function (Blueprint $table): void {
            TenantSchema::tenantForeign($table, 'ai_run_id', 'ai_runs');
        });

        Schema::create('ai_attempts', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('run_id');
            $table->unsignedSmallInteger('attempt_no');
            $table->uuid('connection_id')->nullable();
            $table->uuid('model_id')->nullable();
            $table->string('protocol', 32)->nullable();
            $table->string('request_id_safe', 200)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cached_tokens')->nullable();
            // usage 未知不可記 0（AI-013）。
            $table->string('usage_state', 16)->default('unavailable');
            $table->decimal('cost_estimate', 18, 8)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('finish_reason', 32)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestampTz('created_at');
            TenantSchema::tenantForeign($table, 'run_id', 'ai_runs', 'cascade');
            $table->unique(['run_id', 'attempt_no']);
        });
        TenantSchema::enum('ai_attempts', 'usage_state', ['known', 'estimated', 'unavailable']);
        TenantSchema::enableRls('ai_attempts');

        Schema::create('ai_run_sources', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('run_id');
            $table->uuid('chunk_id');
            $table->uuid('version_id');
            $table->string('source_label', 16);
            $table->unsignedSmallInteger('rank');
            $table->float('score')->nullable();
            $table->jsonb('profile_snapshot')->default('{}');
            $table->boolean('cited')->default(false);
            TenantSchema::tenantForeign($table, 'run_id', 'ai_runs', 'cascade');
            TenantSchema::tenantForeign($table, 'chunk_id', 'knowledge_chunks');
            $table->unique(['run_id', 'source_label']);
        });
        TenantSchema::enableRls('ai_run_sources');

        Schema::create('tool_executions', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('run_id');
            $table->string('tool_key', 64);
            $table->jsonb('actor_scope');
            $table->jsonb('input_safe');
            $table->jsonb('result_safe')->nullable();
            $table->string('state', 16);
            $table->string('error_code', 64)->nullable();
            $table->string('external_request_id', 200)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            TenantSchema::tenantForeign($table, 'run_id', 'ai_runs', 'cascade');
        });
        TenantSchema::enum('tool_executions', 'state', ['running', 'succeeded', 'denied', 'failed']);
        TenantSchema::enableRls('tool_executions');

        Schema::create('usage_budgets', function (Blueprint $table): void {
            TenantSchema::tenantKeys($table);
            $table->uuid('brand_id')->nullable();
            $table->uuid('inbox_id')->nullable();
            $table->string('period', 16);
            $table->date('period_start');
            $table->string('currency', 3);
            $table->decimal('limit_amount', 18, 8);
            $table->decimal('reserved_amount', 18, 8)->default(0);
            $table->decimal('spent_amount', 18, 8)->default(0);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
            TenantSchema::tenantForeign($table, 'brand_id', 'brands');
            TenantSchema::tenantForeign($table, 'inbox_id', 'inboxes');
        });
        DB::statement('CREATE UNIQUE INDEX usage_budgets_scope_unique ON usage_budgets (workspace_id, brand_id, inbox_id, period, period_start) NULLS NOT DISTINCT');
        TenantSchema::enum('usage_budgets', 'period', ['day', 'month']);
        TenantSchema::check('usage_budgets', 'usage_budgets_amounts', 'reserved_amount >= 0 AND spent_amount >= 0 AND limit_amount >= 0');
        TenantSchema::enableRls('usage_budgets');
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropForeign(['workspace_id', 'ai_run_id']);
        });
        Schema::table('inboxes', function (Blueprint $table): void {
            $table->dropForeign(['workspace_id', 'ai_profile_id']);
        });
        Schema::table('knowledge_documents', function (Blueprint $table): void {
            $table->dropForeign(['workspace_id', 'published_version_id']);
        });
        foreach (['usage_budgets', 'tool_executions', 'ai_run_sources', 'ai_attempts', 'ai_runs',
            'knowledge_evaluation_runs', 'knowledge_evaluations', 'knowledge_index_builds', 'knowledge_embeddings',
            'knowledge_keywords', 'knowledge_chunks', 'knowledge_versions', 'knowledge_documents',
            'inbox_knowledge_bases', 'knowledge_bases', 'ai_profiles', 'embedding_profiles', 'ai_models',
            'provider_connections'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
