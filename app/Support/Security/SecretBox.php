<?php

declare(strict_types=1);

namespace App\Support\Security;

use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

/**
 * Envelope encryption：每筆秘密以隨機資料金鑰（DEK）加密，DEK 再以主金鑰（KEK）加密。
 *
 * 格式：`yacs1.<kid>.<base64url(wrapNonce|wrappedDek)>.<base64url(dataNonce|ciphertext)>`
 *  - 演算法：XChaCha20-Poly1305（libsodium，維護中的標準實作，不自製演算法）。
 *  - kid 指向 config('yacs.secrets.keys') 中的 KEK，可多把並存以支援輪換：
 *    新資料用 active kid，舊資料仍可用舊 kid 解開，之後以 rewrap 重新包裝。
 *  - associated data 綁定用途（例如 `provider_connection:<id>`），避免密文被搬到別處解開。
 *
 * 解密後的明文只應在需要的那一刻使用（例如注入 HTTP header），不得寫入 log/DTO。
 */
final class SecretBox
{
    private const PREFIX = 'yacs1';

    /** @var array<string, string> kid => 32-byte key */
    private array $keys = [];

    /**
     * @param  array<string, string|null>  $keys  kid => base64 key
     */
    public function __construct(
        #[SensitiveParameter] array $keys,
        private readonly string $activeKeyId,
    ) {
        foreach ($keys as $kid => $encoded) {
            if ($encoded === null || $encoded === '') {
                continue;
            }
            $raw = base64_decode($encoded, true);
            if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new InvalidArgumentException("Secret encryption key [{$kid}] must be 32 bytes, base64 encoded.");
            }
            $this->keys[(string) $kid] = $raw;
        }
    }

    public function isConfigured(): bool
    {
        return isset($this->keys[$this->activeKeyId]);
    }

    public function encrypt(#[SensitiveParameter] string $plaintext, string $context): string
    {
        $kek = $this->key($this->activeKeyId);
        $dek = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();

        $wrapNonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $wrapped = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($dek, $this->aad($context), $wrapNonce, $kek);

        $dataNonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $this->aad($context), $dataNonce, $dek);
        sodium_memzero($dek);

        return implode('.', [
            self::PREFIX,
            $this->activeKeyId,
            self::b64($wrapNonce.$wrapped),
            self::b64($dataNonce.$cipher),
        ]);
    }

    public function decrypt(string $envelope, string $context): string
    {
        $parts = explode('.', $envelope);
        if (count($parts) !== 4 || $parts[0] !== self::PREFIX) {
            throw new RuntimeException('Malformed secret envelope.');
        }
        [, $kid, $wrappedPart, $dataPart] = $parts;
        $kek = $this->key($kid);
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        $wrappedRaw = self::unb64($wrappedPart);
        $dek = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($wrappedRaw, $nonceLength), $this->aad($context), substr($wrappedRaw, 0, $nonceLength), $kek,
        );
        if ($dek === false) {
            throw new RuntimeException('Secret envelope could not be unwrapped.');
        }

        $dataRaw = self::unb64($dataPart);
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($dataRaw, $nonceLength), $this->aad($context), substr($dataRaw, 0, $nonceLength), $dek,
        );
        sodium_memzero($dek);
        if ($plain === false) {
            throw new RuntimeException('Secret envelope could not be decrypted.');
        }

        return $plain;
    }

    /**
     * 金鑰輪換：以目前 active kid 重新包裝。
     */
    public function rewrap(string $envelope, string $context): string
    {
        return $this->encrypt($this->decrypt($envelope, $context), $context);
    }

    public function keyIdOf(string $envelope): ?string
    {
        $parts = explode('.', $envelope);

        return count($parts) === 4 ? $parts[1] : null;
    }

    private function key(string $kid): string
    {
        if (! isset($this->keys[$kid])) {
            throw new RuntimeException("Secret encryption key [{$kid}] is not configured.");
        }

        return $this->keys[$kid];
    }

    private function aad(string $context): string
    {
        return self::PREFIX.'|'.$context;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $encoded): string
    {
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($raw === false) {
            throw new RuntimeException('Malformed secret envelope.');
        }

        return $raw;
    }
}
