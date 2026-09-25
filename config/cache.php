<?php

/*
|--------------------------------------------------------------------------
| 快取設定
|--------------------------------------------------------------------------
|
| 正式/開發使用 redis-cache instance（可淘汰）；測試使用 array。
| 快取只能放可重建的資料，不可作為授權或訊息的權威來源。
| 所有 key 需含 workspace 範圍（例如 `ws:<id>:...`），避免跨租戶共用。
|
*/

return [

    'default' => env('CACHE_STORE', 'redis'),

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'lock_connection' => 'default',
        ],

    ],

    'prefix' => env('CACHE_PREFIX', 'yacs-cache:'),

];
