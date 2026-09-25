<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Http\Resources;

/**
 * 收件匣行為設定（jsonb `inboxes.settings`）的白名單與驗證規則。
 *
 * - offline_message：無客服在線/非營業時間時顯示給訪客的文案。
 * - auto_assign：是否自動分派給可用客服（否則留在佇列等人接）。
 * - csat_enabled：結案後是否邀請評分。
 * - require_verified_for_attachments：僅已驗證會員可上傳附件。
 * - privacy_notice：隱私說明文字。
 */
final class InboxSettings
{
    private const KEYS = ['offline_message', 'auto_assign', 'csat_enabled', 'require_verified_for_attachments', 'privacy_notice'];

    /**
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = 'settings'): array
    {
        return [
            $prefix => ['sometimes', 'array:'.implode(',', self::KEYS)],
            $prefix.'.offline_message' => ['sometimes', 'nullable', 'string', 'max:500'],
            $prefix.'.auto_assign' => ['sometimes', 'boolean:strict'],
            $prefix.'.csat_enabled' => ['sometimes', 'boolean:strict'],
            $prefix.'.require_verified_for_attachments' => ['sometimes', 'boolean:strict'],
            $prefix.'.privacy_notice' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function sanitize(array $settings): array
    {
        return array_merge(
            ['auto_assign' => true, 'csat_enabled' => true, 'require_verified_for_attachments' => false, 'offline_message' => null, 'privacy_notice' => null],
            array_intersect_key($settings, array_flip(self::KEYS)),
        );
    }
}
