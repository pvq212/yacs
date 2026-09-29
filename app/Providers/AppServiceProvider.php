<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Ai\Adapters\LaravelAiAdapter;
use App\Modules\Ai\Adapters\NativeHttpAdapter;
use App\Modules\Ai\Contracts\ChatGateway;
use App\Modules\Ai\Contracts\EmbeddingGateway;
use App\Modules\Audit\AuditLogger;
use App\Modules\Files\Contracts\FileScanner;
use App\Modules\Files\Scanners\ContentValidationScanner;
use App\Support\Extensions\Registry;
use App\Support\Security\LookupDigest;
use App\Support\Security\SecretBox;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * 核心服務綁定。
 *
 * 綁定原則（Octane 長駐程序，ADR-0005）：
 *  - 無狀態、只依設定的服務可用 singleton（SecretBox、LookupDigest）。
 *  - 持有「目前請求/任務」狀態的服務必須 scoped（TenantDatabase、AuditLogger），
 *    Octane 每個請求、queue worker 每個 job 都會重建。
 */
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SecretBox::class, static fn (): SecretBox => new SecretBox(
            (array) config('yacs.secrets.keys', []),
            (string) config('yacs.secrets.active_key_id', 'k1'),
        ));

        $this->app->singleton(LookupDigest::class, static fn (): LookupDigest => new LookupDigest(
            config('yacs.secrets.lookup_key'),
        ));

        $this->app->scoped(TenantDatabase::class, static fn ($app): TenantDatabase => new TenantDatabase(
            $app['db'],
            (string) config('database.default'),
            'yacs_system',
        ));

        $this->app->scoped(AuditLogger::class);
        $this->app->bind(ChatGateway::class, LaravelAiAdapter::class);
        $this->app->bind(EmbeddingGateway::class, NativeHttpAdapter::class);
        $this->app->singleton(Registry::class, function (): Registry {
            $registry = new Registry;
            $registry->register('file_scanner', 'content_validation', ContentValidationScanner::class);

            return $registry;
        });
        $this->app->bind(FileScanner::class, fn () => $this->app->make(Registry::class)->resolve('file_scanner', (string) config('yacs.files.scanner')));
    }

    public function boot(): void
    {
        // 開發期提早發現 N+1 與未定義屬性；不影響正式環境。
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::preventLazyLoading(! $this->app->isProduction());

        $this->registerRateLimiters();
        $this->registerQueueTenantReset();
    }

    /**
     * 限流起點（API_CONTRACT §8）。回應 429 並帶 Retry-After。
     */
    private function registerRateLimiters(): void
    {
        $limits = (array) config('yacs.rate_limits');

        RateLimiter::for('staff-login', static fn (Request $request): array => [
            Limit::perMinute((int) config('yacs.staff.login_max_attempts_per_minute', 5))
                ->by('login:'.mb_strtolower((string) $request->json('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by('login-ip:'.$request->ip()),
        ]);

        RateLimiter::for('staff-auth-public', static fn (Request $request): Limit => Limit::perMinute(10)->by('auth-public:'.$request->ip()));

        RateLimiter::for('widget-bootstrap', static fn (Request $request): Limit => Limit::perMinutes(10, (int) ($limits['widget_bootstrap_per_10min'] ?? 30))
            ->by('wb:'.$request->ip().'|'.(string) $request->json('inbox_key')));

        RateLimiter::for('widget-identity', static fn (Request $request): Limit => Limit::perMinute((int) ($limits['widget_identity_per_minute'] ?? 60))
            ->by('wi:'.$request->ip().'|'.(string) $request->json('inbox_key')));

        RateLimiter::for('staff', static fn (Request $request): Limit => Limit::perMinute(600)->by('staff:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    /**
     * queue job 開始與結束時重設資料庫租戶設定，避免 worker 在 job 之間殘留 workspace/角色。
     */
    private function registerQueueTenantReset(): void
    {
        // sync connection 在請求流程內同步執行（測試/開發），不可重設請求本身的租戶範圍；
        // 任務本身以 withinWorkspace() 進出範圍，結束後會還原。
        $reset = function (JobProcessing|JobProcessed|JobExceptionOccurred $event): void {
            if ($event->connectionName === 'sync') {
                return;
            }
            $this->app->make(TenantDatabase::class)->reset(rollbackOpenTransactions: true);
        };
        Event::listen(JobProcessing::class, $reset);
        Event::listen(JobProcessed::class, $reset);
        Event::listen(JobExceptionOccurred::class, $reset);
    }
}
