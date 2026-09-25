<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Support;

/**
 * 正規化嵌入來源為 `scheme://host[:port]`（小寫、IDN 轉 punycode、省略預設 port）。
 *
 * 不接受 wildcard、regex、路徑、query 或 fragment；非正式環境允許 http（本機開發）。
 */
final class OriginNormalizer
{
    public static function normalize(string $origin): ?string
    {
        $origin = trim($origin);
        if ($origin === '' || str_contains($origin, '*')) {
            return null;
        }
        $parts = parse_url($origin);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['https', 'http'], true)) {
            return null;
        }
        if ($scheme === 'http' && app()->isProduction() && ! in_array($parts['host'], ['localhost', '127.0.0.1'], true)) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            return null;
        }

        $host = strtolower($parts['host']);
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $host) === 1) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                return null;
            }
            $host = $ascii;
        }
        if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return null;
        }

        $port = $parts['port'] ?? null;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $suffix = ($port !== null && $port !== $defaultPort) ? ':'.$port : '';

        return $scheme.'://'.$host.$suffix;
    }
}
