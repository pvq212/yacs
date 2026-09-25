<?php

declare(strict_types=1);

namespace App\Modules\Identity\Staff;

use App\Modules\Identity\Models\StaffSessionSecurity;
use App\Modules\Identity\Models\User;
use App\Support\Security\LookupDigest;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Staff 登入 session 的建立、驗證與撤銷。
 *
 * Laravel session 負責 cookie 與 payload；本類別另外在 `staff_session_security` 保存
 * 可被伺服器端撤銷的安全紀錄（停用使用者、登出所有裝置、權限撤銷時使用）。
 * session id 只保存 SHA-256 digest，不保存原文。
 */
final class StaffSessionManager
{
    public const SESSION_KEY = 'yacs.staff_session_id';

    /** 絕對有效期（小時）：即使持續活動，也需重新登入。 */
    private const ABSOLUTE_LIFETIME_HOURS = 12;

    /** last_seen_at 更新間隔，避免每個請求都寫 DB。 */
    private const TOUCH_INTERVAL_SECONDS = 60;

    public function establish(User $user, Request $request, bool $mfaVerified): StaffSessionSecurity
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate(true);

        $now = CarbonImmutable::now();
        $security = new StaffSessionSecurity;
        $security->forceFill([
            'user_id' => $user->id,
            'session_id_digest' => $this->digest($request->session()->getId()),
            'mfa_verified_at' => $mfaVerified ? $now : null,
            'ip_digest' => LookupDigest::ip($request->ip()),
            'created_at' => $now,
            'last_seen_at' => $now,
            'expires_at' => $now->addHours(self::ABSOLUTE_LIFETIME_HOURS),
        ])->save();

        $request->session()->put(self::SESSION_KEY, $security->id);
        $user->forceFill(['last_login_at' => $now])->save();

        return $security;
    }

    /**
     * 取得並驗證目前 session 的安全紀錄；無效時回 null（呼叫端應視為未登入）。
     */
    public function current(Request $request): ?StaffSessionSecurity
    {
        if (! $request->hasSession()) {
            return null;
        }
        $id = $request->session()->get(self::SESSION_KEY);
        if (! is_string($id) || $id === '') {
            return null;
        }
        $security = StaffSessionSecurity::query()->find($id);
        if ($security === null || ! $security->isUsable()) {
            return null;
        }
        if (! hash_equals($security->session_id_digest, $this->digest($request->session()->getId()))) {
            return null;
        }
        if ($security->last_seen_at->diffInSeconds(CarbonImmutable::now(), true) > self::TOUCH_INTERVAL_SECONDS) {
            $security->forceFill(['last_seen_at' => CarbonImmutable::now()])->save();
        }

        return $security;
    }

    public function logout(Request $request, string $reason = 'logout'): void
    {
        $security = $this->current($request);
        if ($security !== null) {
            $this->revoke($security, $reason);
        }
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    public function revoke(StaffSessionSecurity $security, string $reason): void
    {
        StaffSessionSecurity::query()
            ->whereKey($security->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => CarbonImmutable::now(), 'session_generation' => $security->session_generation + 1]);
    }

    /**
     * 撤銷某使用者所有 session（停用、重設密碼、MFA 變更時）。
     *
     * @return list<string> 被撤銷的 session 安全紀錄 id（供 realtime 端斷線）
     */
    public function revokeAllForUser(string $userId, ?string $exceptId = null): array
    {
        $query = StaffSessionSecurity::query()->where('user_id', $userId)->whereNull('revoked_at');
        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }
        /** @var list<string> $ids */
        $ids = $query->pluck('id')->all();
        if ($ids !== []) {
            StaffSessionSecurity::query()->whereIn('id', $ids)->update(['revoked_at' => CarbonImmutable::now()]);
        }

        return $ids;
    }

    private function digest(string $sessionId): string
    {
        return hash('sha256', 'staff-session|'.$sessionId);
    }
}
