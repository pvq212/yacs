<?php

declare(strict_types=1);

use App\Http\Middleware\ApplyRequestLocale;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateIntegration;
use App\Http\Middleware\AuthenticateStaff;
use App\Http\Middleware\AuthenticateVisitor;
use App\Http\Middleware\EnforceJsonPayload;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\EnsurePlatformOperator;
use App\Http\Middleware\FlushDeferredAudit;
use App\Http\Middleware\ResetTenantContext;
use App\Http\Middleware\ResolveStaffWorkspace;
use App\Http\Middleware\ResolveWidgetInbox;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyCsrfToken;
use App\Support\Http\ExceptionRenderer;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Middleware\ValidatePostSize;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;

/*
|--------------------------------------------------------------------------
| 應用程式啟動設定
|--------------------------------------------------------------------------
|
| 路由分成四種認證方式各自獨立的群組（見 routes/api.php 與 ADR-0006）：
|   staff        同站 session + CSRF（/ops、/agent 後台）
|   visitor      widget 短期 bearer token
|   integration  服務端 scoped token
|   hooks        原始 body HMAC 簽章
|
*/

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        channels: null,
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->use([
            TrustProxies::class,
            HandleCors::class,
            PreventRequestsDuringMaintenance::class,
            ValidatePostSize::class,
            AssignRequestId::class,
            ResetTenantContext::class,
            SecurityHeaders::class,
        ]);

        // api 群組：不含 session/cookie；各認證群組在 routes/api.php 內再疊加。
        $middleware->group('api', [
            EnforceJsonPayload::class,
            ApplyRequestLocale::class,
            FlushDeferredAudit::class,
            SubstituteBindings::class,
        ]);

        $middleware->group('staff', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            VerifyCsrfToken::class,
        ]);

        $middleware->alias([
            'staff.auth' => AuthenticateStaff::class,
            'staff.workspace' => ResolveStaffWorkspace::class,
            'visitor.auth' => AuthenticateVisitor::class,
            'widget.inbox' => ResolveWidgetInbox::class,
            'integration.auth' => AuthenticateIntegration::class,
            'idempotent' => EnsureIdempotency::class,
            'platform.operator' => EnsurePlatformOperator::class,
        ]);

        $middleware->redirectGuestsTo(fn () => null);
        $middleware->trustProxies(at: env('YACS_TRUSTED_PROXIES') !== null ? explode(',', (string) env('YACS_TRUSTED_PROXIES')) : null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ExceptionRenderer::register($exceptions);
    })->create();
