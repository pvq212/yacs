<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Models;

use App\Support\Database\TenantModel;

/**
 * 角色授予，可限縮到 brand / inbox / team 範圍。
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $membership_id
 * @property string $role_id
 * @property string $scope_type
 * @property string|null $scope_id
 * @property string|null $granted_by_membership_id
 */
final class RoleBinding extends TenantModel
{
    protected $table = 'role_bindings';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
