<?php

declare(strict_types=1);

namespace App\Support\Security;

use RuntimeException;
use SensitiveParameter;

/**
 * 可查詢的個資 digest：HMAC-SHA256(伺服器金鑰, 正規化值)。
 *
 * 用於「以 email 找 contact」等需求；因為帶伺服器金鑰，資料庫外洩時無法以字典暴力還原。
 * 金鑰更換會使既有 digest 失效，需要重新計算（見維運手冊）。
 */
final class LookupDigest
{
    private readonly string $key;

    public function __construct(#[SensitiveParameter] ?string $base64Key)
    {
        $raw = $base64Key !== null ? base64_decode($base64Key, true) : false;
        $this->key = $raw === false ? '' : $raw;
    }

    public function email(#[SensitiveParameter] string $email): string
    {
        return $this->digest('email', mb_strtolower(trim($email)));
    }

    public function digest(string $purpose, #[SensitiveParameter] string $value): string
    {
        if (strlen($this->key) < 32) {
            throw new RuntimeException('YACS_LOOKUP_DIGEST_KEY must be a base64 encoded 32-byte key.');
        }

        return hash_hmac('sha256', $purpose.'|'.$value, $this->key);
    }

    public static function ip(?string $ip): ?string
    {
        // IP 只保存截短 digest 供關聯分析，不保存原文。
        return $ip === null ? null : substr(hash('sha256', 'ip|'.$ip.'|'.config('app.key')), 0, 32);
    }
}
