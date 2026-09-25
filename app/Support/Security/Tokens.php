<?php

declare(strict_types=1);

namespace App\Support\Security;

use SensitiveParameter;

/**
 * 不透明 token 的產生與雜湊。
 *
 * - token 至少 256-bit 隨機值，以 base64url 呈現，可帶可讀前綴（例如 `yacs_at_`）。
 * - DB 只保存 SHA-256 hex；比對以 hash 查詢（hash 本身不可逆，且輸入為高熵隨機值，
 *   因此不需要 password hashing 的慢雜湊）。
 */
final class Tokens
{
    public static function generate(string $prefix = '', int $bytes = 32): string
    {
        return $prefix.rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function hash(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * 公開識別碼（例如 inbox public key）：高熵、不可枚舉，但不是秘密。
     */
    public static function publicKey(string $prefix): string
    {
        return $prefix.strtolower(bin2hex(random_bytes(16)));
    }
}
