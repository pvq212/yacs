<?php

use App\Modules\Identity\Models\User;

/*
|--------------------------------------------------------------------------
| 認證設定
|--------------------------------------------------------------------------
|
| 只有 staff 使用 Laravel session guard（`web`）。訪客與整合 token 由各自的 middleware
| 驗證（AuthenticateVisitor / AuthenticateIntegration），不經過 Laravel guard，
| 避免兩種身分混用（ADR-0006）。
|
| 密碼重設由 App\Modules\Identity\Staff\PasswordResetService 實作（token 只存 hash），
| 不使用框架內建的 password broker。
|
*/

return [

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 30,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,

];
