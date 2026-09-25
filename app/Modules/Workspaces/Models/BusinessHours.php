<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Models;

use App\Support\Database\TenantModel;

/**
 * 營業時間與假日設定。
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string $timezone
 * @property array<string, list<array{0:string,1:string}>> $weekly_schedule
 * @property list<array{date:string,name?:string,closed?:bool}> $holidays
 * @property int $version
 */
final class BusinessHours extends TenantModel
{
    protected $table = 'business_hours';

    protected function casts(): array
    {
        return [
            'weekly_schedule' => 'array',
            'holidays' => 'array',
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
