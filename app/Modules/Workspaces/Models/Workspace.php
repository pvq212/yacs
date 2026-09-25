<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Models;

use App\Support\Database\Model;

/**
 * 租戶（公司）。所有業務資料都以 workspace_id 隔離。
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $timezone
 * @property string $default_locale
 * @property string $status
 * @property array<string, mixed> $settings
 * @property int $knowledge_generation
 * @property int $version
 */
final class Workspace extends Model
{
    protected $table = 'workspaces';

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'knowledge_generation' => 'integer',
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
