<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Models;

use App\Support\Database\TenantModel;

/**
 * 收件匣：網站或渠道的入口；public_key 為公開識別碼（不是秘密）。
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $brand_id
 * @property string $name
 * @property string $public_key
 * @property string $channel_type
 * @property string $status
 * @property string $ai_mode
 * @property string|null $ai_profile_id
 * @property string|null $business_hours_id
 * @property array<string, mixed> $settings
 * @property int $version
 */
final class Inbox extends TenantModel
{
    protected $table = 'inboxes';

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
