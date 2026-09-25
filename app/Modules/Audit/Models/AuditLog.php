<?php

declare(strict_types=1);

namespace App\Modules\Audit\Models;

use App\Support\Database\TenantModel;

/**
 * 稽核紀錄（append-only）；before/after 只保存非 secret 摘要。
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $resource_type
 * @property string|null $resource_id
 * @property array<string, mixed> $scope
 * @property string|null $request_id
 * @property string|null $reason
 * @property array<string, mixed>|null $before_safe
 * @property array<string, mixed>|null $after_safe
 */
final class AuditLog extends TenantModel
{
    protected $table = 'audit_logs';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'before_safe' => 'array',
            'after_safe' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
