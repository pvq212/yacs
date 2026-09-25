<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 在請求交易結束後寫入暫存的「被拒絕操作」稽核紀錄（見 AuditLogger::recordDenied）。
 */
final class FlushDeferredAudit
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $this->audit->flushDeferred();

        return $response;
    }
}
