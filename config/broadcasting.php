<?php

return [
    'default' => env('BROADCAST_CONNECTION', 'reverb'),
    'connections' => [
        'reverb' => ['driver' => 'reverb', 'key' => env('REVERB_APP_KEY'), 'secret' => env('REVERB_APP_SECRET'), 'app_id' => env('REVERB_APP_ID', 'yacs'), 'options' => ['host' => env('REVERB_HOST', '127.0.0.1'), 'port' => (int) env('REVERB_PORT', 8080), 'scheme' => env('REVERB_SCHEME', 'http'), 'useTLS' => env('REVERB_SCHEME', 'http') === 'https'], 'client_options' => ['timeout' => 3, 'connect_timeout' => 2]],
        'null' => ['driver' => 'null'], 'log' => ['driver' => 'log'],
    ],
];
