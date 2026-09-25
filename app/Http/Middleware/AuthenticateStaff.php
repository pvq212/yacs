<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Identity\Models\StaffSessionSecurity;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Staff\MfaService;
use App\Modules\Identity\Staff\StaffSessionManager;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use App\Support\Tenancy\TenantDatabase;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 驗證 staff session：已登入、session 安全紀錄有效（未撤銷/未過期）、使用者啟用中、
 * 且（正式環境要求時）已完成 MFA 設定。
 *
 * 參數 `allow-enrollment`：允許尚未設定 MFA 的使用者存取（僅 /me 與 MFA 設定端點使用）。
 */
final class AuthenticateStaff
{
    public function __construct(
        private readonly StaffSessionManager $sessions,
        private readonly MfaService $mfa,
        private readonly TenantDatabase $tenant,
    ) {}

    public function handle(Request $request, Closure $next, string $mode = 'strict'): Response
    {
        // 每個請求都從 DB 重新載入使用者（長駐程序或測試中 guard 可能快取了舊狀態）。
        Auth::guard('web')->forgetUser();
        $user = Auth::guard('web')->user();
        if (! $user instanceof User) {
            throw new ApiException(ErrorCode::Unauthenticated);
        }

        $security = $this->sessions->current($request);
        if ($security === null || ! $user->isActive()) {
            $this->sessions->logout($request, 'invalid_session');
            throw new ApiException(ErrorCode::Unauthenticated);
        }

        if ($mode !== 'allow-enrollment' && $this->mfa->enrollmentRequired($user)) {
            throw new ApiException(ErrorCode::MfaRequired, null, ['enrollment_required' => true]);
        }

        $this->tenant->setUser($user->id);
        app()->instance(StaffSessionSecurity::class, $security);

        return $next($request);
    }
}
