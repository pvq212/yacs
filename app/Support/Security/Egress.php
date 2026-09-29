<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** 自訂 provider/webhook URL 在每次送出重新檢查並釘住 DNS，不跟隨 redirect。 */
final class Egress
{
    public function request(string $url, int $timeout = 45): PendingRequest
    {
        $parts = parse_url($url);
        $testing = ! app()->isProduction() && config('yacs.egress.allow_insecure_for_testing');
        if (! $parts || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || ! in_array($parts['scheme'] ?? '', $testing ? ['http', 'https'] : ['https'], true)) {
            throw new ApiException(ErrorCode::ValidationFailed, null, ['fields' => ['url' => ['invalid_egress_url']]]);
        }
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! $testing && ! in_array($host.':'.$port, (array) config('yacs.egress.approved_private_targets'), true)) {
            if ($ips === []) {
                throw new ApiException(ErrorCode::TemporarilyUnavailable, null, ['reason' => 'dns_failed']);
            }
            foreach ($ips as $ip) {
                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    throw new ApiException(ErrorCode::Forbidden, null, ['reason' => 'private_egress']);
                }
            }
        }
        $request = Http::connectTimeout(5)->timeout($timeout)->withoutRedirecting();
        if ($ips !== [] && defined('CURLOPT_RESOLVE')) {
            $request = $request->withOptions(['curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.$ips[0]]]]);
        }

        return $request;
    }
}
