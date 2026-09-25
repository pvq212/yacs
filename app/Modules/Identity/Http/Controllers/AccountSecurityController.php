<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Models\StaffSessionSecurity;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Staff\MfaService;
use App\Modules\Identity\Staff\PasswordPolicy;
use App\Modules\Identity\Staff\StaffSessionManager;
use App\Support\Http\ApiException;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use App\Support\Http\Input;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * 目前使用者的帳號安全：MFA 設定/停用、復原碼、修改密碼。
 *
 * 這些端點允許「尚未完成 MFA 設定」的使用者存取（staff.auth:allow-enrollment），
 * 以便正式環境強制 MFA 時能完成首次設定。
 */
final class AccountSecurityController
{
    private const PENDING_KEY = 'yacs.mfa_pending_secret';

    public function __construct(
        private readonly MfaService $mfa,
        private readonly StaffSessionManager $sessions,
    ) {}

    /**
     * POST /api/v1/me/mfa/setup：產生待確認的 TOTP secret（加密暫存於 session）。
     */
    public function setup(Request $request): JsonResponse
    {
        $user = $this->user();
        if ($user->hasMfa()) {
            throw ApiException::invalidState('mfa_already_enabled');
        }
        $secret = $this->mfa->generateSecret();
        $request->session()->put(self::PENDING_KEY, $this->mfa->encryptSecret($user, $secret));

        return ApiResponse::data([
            'secret' => $secret,
            'otpauth_uri' => $this->mfa->provisioningUri($user, $secret),
        ]);
    }

    /**
     * POST /api/v1/me/mfa/confirm：以一組驗證碼確認後啟用，回傳一次性復原碼。
     */
    public function confirm(Request $request): JsonResponse
    {
        $data = Input::validate($request, ['code' => ['required', 'string', 'regex:/^[0-9]{6}$/']]);
        $user = $this->user();
        $pending = $request->session()->get(self::PENDING_KEY);
        if (! is_string($pending) || $user->hasMfa()) {
            throw ApiException::invalidState('mfa_setup_not_started');
        }
        if (! $this->mfa->verifyTotp($user, $data['code'], $pending)) {
            throw new ApiException(ErrorCode::ValidationFailed, __('auth.mfa_invalid'), ['fields' => ['code' => ['invalid']]]);
        }

        $user->forceFill(['mfa_secret_encrypted' => $pending, 'mfa_enabled_at' => CarbonImmutable::now()])->save();
        $request->session()->forget(self::PENDING_KEY);
        $codes = $this->mfa->issueRecoveryCodes($user);

        $current = $this->currentSession();
        $this->sessions->revokeAllForUser($user->id, $current?->id);
        $current?->forceFill(['mfa_verified_at' => CarbonImmutable::now()])->save();

        return ApiResponse::data(['recovery_codes' => $codes]);
    }

    /**
     * POST /api/v1/me/mfa/recovery-codes：以目前驗證碼重新產生復原碼（舊碼全部失效）。
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $data = Input::validate($request, ['code' => ['required', 'string', 'regex:/^[0-9]{6}$/']]);
        $user = $this->user();
        if (! $user->hasMfa() || ! $this->mfa->verifyTotp($user, $data['code'])) {
            throw new ApiException(ErrorCode::ValidationFailed, __('auth.mfa_invalid'), ['fields' => ['code' => ['invalid']]]);
        }

        return ApiResponse::data(['recovery_codes' => $this->mfa->issueRecoveryCodes($user)]);
    }

    /**
     * POST /api/v1/me/mfa/disable：需同時提供密碼與驗證碼。
     */
    public function disable(Request $request): Response
    {
        $data = Input::validate($request, [
            'password' => ['required', 'string', 'max:1024'],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);
        $user = $this->user();
        if (! Hash::check($data['password'], (string) $user->password_hash)) {
            throw new ApiException(ErrorCode::ValidationFailed, __('auth.current_password_invalid'), ['fields' => ['password' => ['invalid']]]);
        }
        if (! $user->hasMfa() || ! $this->mfa->verifyTotp($user, $data['code'])) {
            throw new ApiException(ErrorCode::ValidationFailed, __('auth.mfa_invalid'), ['fields' => ['code' => ['invalid']]]);
        }
        $user->forceFill(['mfa_secret_encrypted' => null, 'mfa_enabled_at' => null, 'mfa_recovery_codes_encrypted' => null, 'mfa_last_timestep' => null])->save();
        $this->sessions->revokeAllForUser($user->id, $this->currentSession()?->id);

        return new Response('', 204);
    }

    /**
     * PUT /api/v1/me/password：修改密碼並撤銷其他裝置的 session。
     */
    public function changePassword(Request $request): Response
    {
        $data = Input::validate($request, [
            'current_password' => ['required', 'string', 'max:1024'],
            'password' => PasswordPolicy::rules(),
        ]);
        $user = $this->user();
        if (! Hash::check($data['current_password'], (string) $user->password_hash)) {
            throw new ApiException(ErrorCode::ValidationFailed, __('auth.current_password_invalid'), ['fields' => ['current_password' => ['invalid']]]);
        }
        $user->forceFill(['password_hash' => Hash::make($data['password'])])->save();
        $this->sessions->revokeAllForUser($user->id, $this->currentSession()?->id);

        return new Response('', 204);
    }

    private function user(): User
    {
        /** @var User */
        return Auth::guard('web')->user();
    }

    private function currentSession(): ?StaffSessionSecurity
    {
        return app()->bound(StaffSessionSecurity::class) ? app(StaffSessionSecurity::class) : null;
    }
}
