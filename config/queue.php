<?php

/*
|--------------------------------------------------------------------------
| 佇列設定
|--------------------------------------------------------------------------
|
| YACS 以 PostgreSQL 的 async_tasks / outbox_events 為任務與事件的「權威紀錄」，
| Redis 佇列只負責「喚醒 worker」。Redis 資料遺失時，dispatcher 與 watchdog
| 會從 DB 找回需重投的任務（見 docs/adr/0003-queue-redis-horizon.md）。
|
| 每種工作負載使用獨立 connection，各自設定 retry_after，並對應 Horizon 的
| 獨立 supervisor，避免 AI 或索引工作吃光核心資源。三者關係必須維持：
|
|     job timeout  <  supervisor timeout  <  retry_after
|
| 由 tests/Unit/Config/QueueTimeoutTest.php 驗證（OPS-005）。
|
*/

$redisQueue = static fn (string $queue, int $retryAfter): array => [
    'driver' => 'redis',
    'connection' => 'queue',
    'queue' => $queue,
    'retry_after' => $retryAfter,
    'block_for' => 5,
    // 任務訊息一律在 DB commit 後才送出（搭配 outbox / async_tasks）。
    'after_commit' => true,
];

return [

    'default' => env('QUEUE_CONNECTION', 'core'),

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        // 核心：訊息後處理、分派、通知等短工作。
        'core' => $redisQueue('core', (int) env('YACS_QUEUE_CORE_RETRY_AFTER', 120)),

        // AI：模型呼叫與 RAG 生成；受 run deadline 約束。
        'ai' => $redisQueue('ai', (int) env('YACS_QUEUE_AI_RETRY_AFTER', 120)),

        // 知識：文件擷取、切段、embedding 等長工作。
        'kb' => $redisQueue('kb', (int) env('YACS_QUEUE_KB_RETRY_AFTER', 330)),

        // 事件：outbox 投遞、realtime fanout、webhook 建立。
        'events' => $redisQueue('events', (int) env('YACS_QUEUE_EVENTS_RETRY_AFTER', 120)),

        // 外部渠道/webhook 實際送出；失敗有獨立重試策略。
        'channels' => $redisQueue('channels', (int) env('YACS_QUEUE_CHANNELS_RETRY_AFTER', 90)),

    ],

    'batching' => [
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'job_batches',
    ],

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'failed_jobs',
    ],

];
