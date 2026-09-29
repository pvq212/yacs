<?php

use App\Modules\Conversations\RealtimePublisher;
use App\Modules\Integrations\ChannelDeliveries;
use App\Modules\Integrations\Webhooks;
use App\Modules\Tasks\Tasks;
use App\Modules\Tasks\Watchdog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('yacs:watchdog', function () {
    app(Watchdog::class)->tick();
    touch(storage_path('framework/watchdog-heartbeat'));
})->purpose('掃描逾時 AI 與稍後處理的案件');
Artisan::command('yacs:dispatch', function () {
    app(Tasks::class)->dispatch();
})->purpose('將持久任務送入獨立佇列');
Artisan::command('yacs:realtime', function () {
    app(RealtimePublisher::class)->tick();
})->purpose('發布已提交的即時事件');
Artisan::command('yacs:webhooks', function () {
    app(Webhooks::class)->tick();
})->purpose('建立 outbox delivery 並重試外部投遞');
Artisan::command('yacs:channels', function () {
    app(ChannelDeliveries::class)->tick();
})->purpose('投遞已提交的渠道回覆');
Schedule::command('yacs:channels')->everyTenSeconds()->withoutOverlapping();
Schedule::command('yacs:watchdog')->everyTenSeconds()->withoutOverlapping();
Schedule::command('yacs:dispatch')->everyFiveSeconds()->withoutOverlapping();
Schedule::command('yacs:realtime')->everyFiveSeconds()->withoutOverlapping();
Schedule::command('yacs:webhooks')->everyTenSeconds()->withoutOverlapping();
