<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 模擬瀏覽器的 staff API 客戶端：真的走 csrf-cookie → login 流程，並在請求間保存 cookie。
 *
 * 不繞過 CSRF、session 安全紀錄或 MFA，讓測試驗證與正式環境相同的路徑。
 */
final class StaffClient
{
    /** @var array<string, string> 原始（已加密）cookie 值 */
    private array $cookies = [];

    public function __construct(private readonly TestCase $test) {}

    public static function loginAs(TestCase $test, Staff|string $who, string $password = World::PASSWORD): self
    {
        $client = new self($test);
        $email = $who instanceof Staff ? $who->user->email : $who;
        $client->csrf();
        $response = $client->post('/api/v1/auth/login', ['email' => $email, 'password' => $password], idempotent: false);
        $response->assertOk();

        return $client;
    }

    public function csrf(): TestResponse
    {
        return $this->request('GET', '/api/v1/auth/csrf-cookie');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(string $uri, array $query = []): TestResponse
    {
        return $this->request('GET', $query === [] ? $uri : $uri.'?'.http_build_query($query));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function post(string $uri, array $body = [], bool $idempotent = true, ?string $key = null): TestResponse
    {
        return $this->request('POST', $uri, $body, $idempotent ? ($key ?? (string) Str::uuid()) : null);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function patch(string $uri, array $body = [], ?string $key = null): TestResponse
    {
        return $this->request('PATCH', $uri, $body, $key ?? (string) Str::uuid());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function put(string $uri, array $body = [], bool $idempotent = false, ?string $key = null): TestResponse
    {
        return $this->request('PUT', $uri, $body, $idempotent ? ($key ?? (string) Str::uuid()) : null);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     */
    public function request(string $method, string $uri, ?array $body = null, ?string $idempotencyKey = null, array $headers = [], bool $withCsrf = true): TestResponse
    {
        $headers = array_merge(['Accept' => 'application/json'], $headers);
        if ($withCsrf && isset($this->cookies['XSRF-TOKEN'])) {
            $headers['X-XSRF-TOKEN'] = $this->cookies['XSRF-TOKEN'];
        }
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $content = $body === null ? null : (string) json_encode($body === [] ? new \stdClass : $body, JSON_UNESCAPED_UNICODE);
        if ($content !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }

        // 每次 HTTP 都重新建立 guard，避免測試中的快取使用者跨瀏覽器殘留。
        Auth::forgetGuards();
        // Laravel 測試會重用 Session Store；清空記憶體後再由該瀏覽器 cookie 載入。
        app('session')->driver()->flush();

        // cookie 值已由伺服器加密，直接原樣送回（不再二次加密）。
        $response = $this->test->call($method, $uri, [], $this->cookies, [], $server, $content);

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->cookies[$cookie->getName()]);
            } else {
                $this->cookies[$cookie->getName()] = (string) $cookie->getValue();
            }
        }

        return $response;
    }

    public function forgetCsrf(): void
    {
        unset($this->cookies['XSRF-TOKEN']);
    }
}
