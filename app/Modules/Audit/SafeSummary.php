<?php

declare(strict_types=1);

namespace App\Modules\Audit;

/**
 * 移除疑似敏感欄位，產生可寫入稽核/日誌的安全摘要。
 *
 * 採黑名單 + 值長度限制的雙重保護：欄位名稱含 secret/token/password/key 等字樣一律改為
 * `[redacted]`；長字串截斷，避免把整段對話或文件寫進稽核。
 */
final class SafeSummary
{
    private const SENSITIVE = '/(secret|token|password|passwd|api[_-]?key|private|credential|authorization|cookie|assertion|mfa|otp|signature|hash)/i';

    private const MAX_STRING = 300;

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function filter(array $data, int $depth = 0): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE, $key) === 1) {
                $out[$key] = $value === null ? null : '[redacted]';

                continue;
            }
            if (is_array($value)) {
                $out[$key] = $depth >= 3 ? '[truncated]' : self::filter($value, $depth + 1);
            } elseif (is_string($value) && mb_strlen($value) > self::MAX_STRING) {
                $out[$key] = mb_substr($value, 0, self::MAX_STRING).'…';
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            } else {
                $out[$key] = '[object]';
            }
        }

        return $out;
    }
}
