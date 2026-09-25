<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/**
 * Staff 寫入請求的 CSRF 檢查（`X-XSRF-TOKEN` header，值為 XSRF-TOKEN cookie）。
 *
 * 與框架預設不同：測試環境也「不略過」檢查，讓 SEC-010 等測試驗證真實行為；
 * 測試用的 StaffClient 會先取得 CSRF cookie 再送出請求。
 */
final class VerifyCsrfToken extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
