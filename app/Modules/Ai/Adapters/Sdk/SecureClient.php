<?php

declare(strict_types=1);

namespace App\Modules\Ai\Adapters\Sdk;

use App\Support\Security\Egress;
use Illuminate\Http\Client\PendingRequest;

/** SDK 保留協定、message 與回應解析；傳輸必須走同一個防 SSRF 邊界。 */
trait SecureClient
{
    protected function createClient(string $baseUrl, array $headers = [], array $configuredHeaders = [], ?int $timeout = 60, bool $throw = true): PendingRequest
    {
        // gateway 不接受 SDK 預設的 web-fetch beta；此 adapter 不啟用供應商工具。
        unset($headers['anthropic-beta']);
        $request = app(Egress::class)->request($baseUrl, $timeout ?? 45)->baseUrl($baseUrl)->withHeaders($headers)->acceptJson();

        // 不讓租戶設定的 headers 覆寫 Authorization/Host；目前不開放任意 header。
        return $throw ? $request->throw() : $request;
    }
}
