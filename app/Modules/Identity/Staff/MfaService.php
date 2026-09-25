<?php

declare(strict_types=1);

namespace App\Modules\Identity\Staff;

use App\Modules\Identity\Models\User;
use App\Support\Security\SecretBox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use SensitiveParameter;

/**
 * TOTP 兩步驟驗證（RFC 6238，使用維護中的 pragmarx/google2fa，不自製演算法）。
 *
 * - secret 以 SecretBox（envelope encryption）保存，綁定 `user_mfa:<user id>` 用途。
 * - 驗證碼允許 ±1 個時間步的時鐘誤差，並記錄最後使用的時間步防止重放。
 * - 復原碼只顯示一次，保存 SHA-256 hash；使用後即移除。
 */
final class MfaService
{
    private const RECOVERY_CODE_COUNT = 10;

    public function __construct(
        private readonly SecretBox $secrets,
        private readonly Google2FA $google2fa = new Google2FA,
    ) {}

    /**
     * 正式環境是否要求此使用者先完成 MFA 設定才能使用後台。
     */
    public function enrollmentRequired(User $user): bool
    {
        if ($user->hasMfa()) {
            return false;
        }
        $mode = (string) config('yacs.staff.mfa_enforcement', 'off');

        return $mode === 'all';
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function provisioningUri(User $user, #[SensitiveParameter] string $secret): string
    {
        return $this->google2fa->getQRCodeUrl((string) config('app.name', 'YACS'), $user->email, $secret);
    }

    public function encryptSecret(User $user, #[SensitiveParameter] string $secret): string
    {
        return $this->secrets->encrypt($secret, 'user_mfa:'.$user->id);
    }

    /**
     * 驗證 TOTP；成功時原子地更新最後使用時間步（同一碼無法用兩次）。
     */
    public function verifyTotp(User $user, #[SensitiveParameter] string $code, ?string $encryptedSecret = null): bool
    {
        $encrypted = $encryptedSecret ?? $user->mfa_secret_encrypted;
        if ($encrypted === null || preg_match('/^[0-9]{6}$/', $code) !== 1) {
            return false;
        }
        $secret = $this->secrets->decrypt($encrypted, 'user_mfa:'.$user->id);
        // 傳入 0 而非 null：google2fa 在 oldTimestamp 為 null 時只回 true，不回時間步。
        $timestep = $this->google2fa->verifyKeyNewer($secret, $code, (int) ($user->mfa_last_timestep ?? 0), 1);
        if ($timestep === false || $timestep === true) {
            return false;
        }

        // CAS：只有在時間步前進時才接受，避免兩個並發請求使用同一碼。
        $updated = DB::table('users')
            ->where('id', $user->id)
            ->where(function ($q) use ($timestep): void {
                $q->whereNull('mfa_last_timestep')->orWhere('mfa_last_timestep', '<', $timestep);
            })
            ->update(['mfa_last_timestep' => $timestep]);
        if ($updated === 1) {
            $user->mfa_last_timestep = $timestep;
        }

        return $updated === 1;
    }

    /**
     * @return list<string> 明文復原碼（只回傳這一次）
     */
    public function issueRecoveryCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = Str::lower(Str::random(5).'-'.Str::random(5));
        }
        $hashes = array_map(static fn (string $c): string => hash('sha256', 'mfa-recovery|'.$c), $codes);
        $user->forceFill(['mfa_recovery_codes_encrypted' => $this->secrets->encrypt((string) json_encode($hashes), 'user_mfa_recovery:'.$user->id)])->save();

        return $codes;
    }

    public function consumeRecoveryCode(User $user, #[SensitiveParameter] string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            /** @var User|null $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if ($locked === null || $locked->mfa_recovery_codes_encrypted === null) {
                return false;
            }
            /** @var list<string> $hashes */
            $hashes = json_decode($this->secrets->decrypt($locked->mfa_recovery_codes_encrypted, 'user_mfa_recovery:'.$user->id), true) ?: [];
            $needle = hash('sha256', 'mfa-recovery|'.Str::lower(trim($code)));
            $index = null;
            foreach ($hashes as $i => $hash) {
                if (hash_equals($hash, $needle)) {
                    $index = $i;
                }
            }
            if ($index === null) {
                return false;
            }
            array_splice($hashes, $index, 1);
            $locked->forceFill(['mfa_recovery_codes_encrypted' => $this->secrets->encrypt((string) json_encode($hashes), 'user_mfa_recovery:'.$user->id)])->save();

            return true;
        });
    }
}
