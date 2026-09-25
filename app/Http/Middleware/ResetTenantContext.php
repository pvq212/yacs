<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantDatabase;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 每個 HTTP 請求開始時把資料庫 session 的租戶設定清空（RESET ROLE + 清除 yacs.*）。
 *
 * Octane 長駐程序會重用 DB 連線；請求結束後的清理由 OctaneTenantReset listener 負責，
 * 這裡的「開始時重設」是第二道保險（SEC-006）。
 */
final class ResetTenantContext
{
    public function __construct(private readonly TenantDatabase $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tenant->reset();

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->tenant->reset(rollbackOpenTransactions: ! app()->runningUnitTests());
    }
}
