<?php

/*
|--------------------------------------------------------------------------
| 資料庫設定
|--------------------------------------------------------------------------
|
| YACS 只支援 PostgreSQL（含 pgvector 與 PGroonga）；狀態競態、複合外鍵、
| Row Level Security 與向量檢索都依賴 PostgreSQL 行為，不提供 SQLite/MySQL。
|
| 連線分工（詳見 docs/adr/0004-postgres-rls-roles.md）：
|   pgsql          應用程式執行期連線，使用非 owner、非 BYPASSRLS 的 runtime 角色。
|   pgsql_migrator 僅供 migration 使用的 owner 角色；一般請求與 worker 不得使用。
|
| 跨 workspace 的系統工作不另開連線，而是在同一連線上以
| `SET ROLE yacs_system` 暫時取得 BYPASSRLS（見 App\Support\Tenancy\TenantDatabase）。
|
*/

$pgsql = [
    'driver' => 'pgsql',
    'url' => env('DB_URL'),
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'yacs'),
    'username' => env('DB_USERNAME', 'yacs_runtime'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => 'utf8',
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => env('DB_SSLMODE', 'prefer'),
    // 伺服器端設定：所有時間以 UTC 儲存與比較。
    'timezone' => 'UTC',
];

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        'pgsql' => $pgsql,

        'pgsql_migrator' => array_merge($pgsql, [
            'username' => env('DB_MIGRATOR_USERNAME', 'yacs_owner'),
            'password' => env('DB_MIGRATOR_PASSWORD', ''),
        ]),

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis
    |--------------------------------------------------------------------------
    |
    | 佇列與快取使用「不同 Redis instance」（見 docs/adr/0003-queue-redis-horizon.md）：
    |   queue   noeviction + AOF 持久化，只放小型任務訊息（task id 與 metadata）。
    |   default/cache  可淘汰，放快取、限流、presence 與 Reverb scaling。
    | 僅分 logical DB 不等於記憶體與故障隔離，因此 host 分開設定。
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => 'redis',
            'prefix' => env('REDIS_PREFIX', 'yacs:'),
            'persistent' => false,
        ],

        'default' => [
            'url' => env('REDIS_CACHE_URL'),
            'host' => env('REDIS_CACHE_HOST', '127.0.0.1'),
            'username' => env('REDIS_CACHE_USERNAME'),
            'password' => env('REDIS_CACHE_PASSWORD'),
            'port' => env('REDIS_CACHE_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '0'),
            'max_retries' => 3,
            'backoff_algorithm' => 'decorrelated_jitter',
            'backoff_base' => 100,
            'backoff_cap' => 1000,
        ],

        'cache' => [
            'url' => env('REDIS_CACHE_URL'),
            'host' => env('REDIS_CACHE_HOST', '127.0.0.1'),
            'username' => env('REDIS_CACHE_USERNAME'),
            'password' => env('REDIS_CACHE_PASSWORD'),
            'port' => env('REDIS_CACHE_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB_CACHE', '1'),
            'max_retries' => 3,
            'backoff_algorithm' => 'decorrelated_jitter',
            'backoff_base' => 100,
            'backoff_cap' => 1000,
        ],

        'queue' => [
            'url' => env('REDIS_QUEUE_URL'),
            'host' => env('REDIS_QUEUE_HOST', '127.0.0.1'),
            'username' => env('REDIS_QUEUE_USERNAME'),
            'password' => env('REDIS_QUEUE_PASSWORD'),
            'port' => env('REDIS_QUEUE_PORT', '6379'),
            'database' => env('REDIS_QUEUE_DB', '0'),
            'max_retries' => 3,
            'backoff_algorithm' => 'decorrelated_jitter',
            'backoff_base' => 100,
            'backoff_cap' => 1000,
        ],

    ],

];
