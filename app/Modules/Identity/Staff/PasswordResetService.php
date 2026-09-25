<?php

declare(strict_types=1);

namespace App\Modules\Identity\Staff;

use App\Modules\Identity\Mail\PasswordResetMail;
use App\Modules\Identity\Models\User;
use App\Support\Security\Tokens;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use SensitiveParameter;

/**
 * 密碼重設：token 單次、短效（預設 30 分鐘），DB 只存 hash；成功後撤銷所有 session。
 *
 * 申請端點對「帳號不存在」與「存在」回應一致，避免帳號探測。
 */
final class PasswordResetService
{
    public function __construct(private readonly StaffSessionManager $sessions) {}

    public function request(string $email): void
    {
        $normalized = User::normalizeEmail($email);
        $user = User::query()->where('email_normalized', $normalized)->where('status', 'active')->first();
        if ($user === null) {
            return;
        }
        $token = Tokens::generate('yacs_pr_');
        DB::table('password_reset_tokens')->upsert([
            'email_normalized' => $normalized,
            'token_hash' => Tokens::hash($token),
            'created_at' => CarbonImmutable::now(),
        ], ['email_normalized'], ['token_hash', 'created_at']);

        Mail::to($user->email)->locale($user->locale ?? (string) config('yacs.locale'))->queue(new PasswordResetMail($user->name, $token));
    }

    public function reset(string $email, #[SensitiveParameter] string $token, #[SensitiveParameter] string $password): bool
    {
        $normalized = User::normalizeEmail($email);
        $ttl = (int) config('yacs.staff.password_reset_ttl_minutes', 30);

        return DB::transaction(function () use ($normalized, $token, $password, $ttl): bool {
            $row = DB::table('password_reset_tokens')->where('email_normalized', $normalized)->lockForUpdate()->first();
            if ($row === null
                || ! hash_equals((string) $row->token_hash, Tokens::hash($token))
                || CarbonImmutable::parse((string) $row->created_at)->addMinutes($ttl)->isPast()) {
                return false;
            }
            DB::table('password_reset_tokens')->where('email_normalized', $normalized)->delete();

            $user = User::query()->where('email_normalized', $normalized)->where('status', 'active')->first();
            if ($user === null) {
                return false;
            }
            $user->forceFill(['password_hash' => Hash::make($password)])->save();
            $this->sessions->revokeAllForUser($user->id);

            return true;
        });
    }
}
