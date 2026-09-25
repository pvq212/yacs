<?php

/*
|--------------------------------------------------------------------------
| YACS 產品設定
|--------------------------------------------------------------------------
|
| 對應 docs/spec/CONFIG_DEFAULTS.yaml 的起始值。這些數字是設計預設，
| 不是容量或效能保證；正式環境請依實測調整並記錄於部署手冊。
|
| 可由 workspace/inbox 覆寫的值（例如 AI 模式）另存於資料庫設定，
| 這裡只放平台層級的預設與上下限。
|
*/

return [

    // 對外識別字樣：集中管理，避免品牌字串散落各處（HTTP header、SDK 全域名稱等）。
    'brand' => [
        'product_name' => 'YACS',
        'header_prefix' => 'X-Yacs-',
        'sdk_global' => 'Yacs',
        'jwt_audience' => 'yacs:visitor',
        'sdk_version' => '1.0.0',
    ],

    'locale' => 'zh_TW',
    'timezone' => 'Asia/Taipei',

    'identity' => [
        // 宿主後端簽發的會員身分 JWT：演算法固定，不接受 token 自選。
        'algorithm' => 'HS256',
        'assertion_ttl_seconds' => 60,
        'clock_skew_seconds' => 30,
        'access_token_ttl_seconds' => 900,
        'refresh_idle_ttl_seconds' => 86400,
        'anonymous_refresh_idle_ttl_seconds' => 86400,
        'authorization_cache_max_seconds' => 60,
        'merge_anonymous_history_automatically' => false,
        // 簽章金鑰最少位元組數（256-bit）。
        'min_secret_bytes' => 32,
    ],

    'staff' => [
        'mfa_enforcement' => env('YACS_MFA_ENFORCEMENT', env('APP_ENV') === 'production' ? 'all' : 'off'),
        'invitation_ttl_hours' => 72,
        'password_reset_ttl_minutes' => 30,
        'login_max_attempts_per_minute' => 5,
    ],

    'conversation' => [
        'reopen_window_hours' => 72,
        'default_agent_capacity' => 5,
        'capacity_count_statuses' => ['open', 'waiting_customer'],
        'heartbeat_interval_seconds' => 30,
        'presence_stale_seconds' => 90,
        'reassignment_grace_seconds' => 300,
    ],

    'messages' => [
        'text_max_characters' => 8000,
        'request_max_bytes' => 65536,
        'page_default' => 50,
        'page_max' => 100,
        'durable_event_retention_days' => 7,
        'idempotent_response_retention_hours' => 24,
    ],

    'widget' => [
        'width_px' => 380,
        'height_px' => 620,
        'initial_position' => 'bottom-right',
        'command_buffer_max' => 50,
        'polling_foreground_seconds' => 5,
        'polling_background_seconds' => 30,
        'visitor_provisional_ai_streaming' => false,
    ],

    'ai' => [
        'default_mode' => env('YACS_AI_DEFAULT_MODE', 'disabled'),
        'debounce_ms' => 800,
        'connection_timeout_seconds' => 5,
        'request_timeout_seconds' => 45,
        'run_deadline_seconds' => 90,
        'max_provider_attempts' => 2,
        'max_tool_calls' => 2,
        'tool_total_budget_seconds' => 10,
        'max_clarifications' => 1,
        'circuit_failure_threshold' => 5,
        'circuit_open_seconds' => 60,
        'cross_provider_fallback' => false,
        'preserve_raw_provider_response' => false,
        'preserve_hidden_reasoning' => false,
        'provider_debug_retention_days' => (int) env('YACS_PROVIDER_DEBUG_RETENTION_DAYS', 0),
    ],

    'rag' => [
        'chunk_target_tokens' => 600,
        'overlap_tokens' => 80,
        'vector_candidates' => 20,
        'lexical_candidates' => 20,
        'fused_candidates' => 8,
        'context_candidates' => 5,
        'rrf_k' => 60,
        'rerank_enabled' => false,
        'visibility_default' => 'staff_only',
        // 精確檢索與 ANN 切換門檻（chunk 數）；ANN 索引需由維運指令建立。
        'exact_search_max_chunks' => 50000,
    ],

    /*
    | 佇列時間關係：job timeout < supervisor timeout < retry_after（OPS-005）。
    | retry_after 實際值在 config/queue.php，這裡記錄 job 與 supervisor 的值。
    */
    'queues' => [
        'payload_target_max_bytes' => 16384,
        'core' => ['job_timeout' => 30, 'supervisor_timeout' => 40, 'max_attempts' => 3],
        'ai' => ['job_timeout' => 70, 'supervisor_timeout' => 80, 'max_attempts' => 2],
        'kb' => ['job_timeout' => 240, 'supervisor_timeout' => 270, 'max_attempts' => 3],
        'events' => ['job_timeout' => 30, 'supervisor_timeout' => 40, 'max_attempts' => 5],
        'channels' => ['job_timeout' => 20, 'supervisor_timeout' => 30, 'max_attempts' => 3],
        'watchdog_interval_seconds' => 10,
        // lease 預設長度：略長於對應 job timeout。
        'lease_seconds' => ['core' => 45, 'ai' => 85, 'kb' => 280, 'events' => 45, 'channels' => 35],
    ],

    'files' => [
        'disk' => env('YACS_FILES_DISK', 'private'),
        'chat_max_bytes' => 10485760,
        'chat_max_per_message' => 5,
        'knowledge_max_bytes' => 20971520,
        'pdf_page_limit' => 200,
        'image_max_pixels' => 40_000_000,
        'signed_download_ttl_seconds' => 60,
        'upload_url_ttl_seconds' => 600,
        'allowed_chat_mimes' => ['image/png', 'image/jpeg', 'image/webp', 'application/pdf', 'text/plain'],
        'allowed_knowledge_mimes' => ['text/plain', 'text/markdown', 'application/pdf'],
        // 使用者決議不預設病毒掃描；介面保留，可於 extensions 註冊 ClamAV 等 scanner。
        'scanner' => env('YACS_FILE_SCANNER', 'content_validation'),
        'scan_failure_policy' => 'quarantine',
    ],

    'webhooks' => [
        'signature_algorithm' => 'hmac-sha256',
        'timestamp_window_seconds' => 300,
        'connection_timeout_seconds' => 3,
        'response_timeout_seconds' => 10,
        'retry_delays_seconds' => [30, 120, 600, 3600, 21600, 86400],
        'max_attempts' => 10,
        'follow_redirects' => false,
        'response_excerpt_bytes' => 512,
    ],

    'egress' => [
        // platform operator 明確批准的內網目的地（host:port），一般租戶無法自行開啟。
        'approved_private_targets' => array_filter(explode(',', (string) env('YACS_EGRESS_APPROVED_PRIVATE_TARGETS', ''))),
        // 開發/測試時允許 http 與本機目的地（例如 fake-ai）；正式環境必須為 false。
        'allow_insecure_for_testing' => (bool) env('YACS_EGRESS_ALLOW_INSECURE_FOR_TESTING', false),
    ],

    'retention' => [
        'conversations_days' => 180,
        'attachments_days' => 180,
        'audit_days' => 365,
        'ai_metadata_days' => 90,
        'raw_webhook_days' => 7,
        'raw_provider_debug_days' => 0,
    ],

    'rate_limits' => [
        'widget_bootstrap_per_10min' => 30,
        'widget_identity_per_minute' => 60,
        'visitor_messages_per_minute' => 20,
        'api_client_per_second' => 100,
    ],

    'secrets' => [
        // envelope encryption 的 key id 與金鑰（base64, 32 bytes）；可多把輪換。
        'active_key_id' => env('YACS_SECRET_KEY_ID', 'k1'),
        'keys' => array_filter([
            env('YACS_SECRET_KEY_ID', 'k1') => env('YACS_SECRET_ENCRYPTION_KEY'),
        ]),
        // 查詢用 digest（例如 email lookup）的伺服器金鑰。
        'lookup_key' => env('YACS_LOOKUP_DIGEST_KEY'),
    ],

];
