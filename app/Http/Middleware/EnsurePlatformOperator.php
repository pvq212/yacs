<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 僅允許平台維運者（系統健康頁、Horizon、內網 egress 批准）。
 */
final class EnsurePlatformOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        if (! $user instanceof User || ! $user->is_platform_operator) {
            throw new ApiException(ErrorCode::Forbidden);
        }

        return $next($request);
    }
}
