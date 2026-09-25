<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Workspaces\Models\WorkspaceMembership;
use App\Support\Database\Model;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 全域 staff 使用者（可加入多個 workspace）。
 *
 * 權限永遠透過 WorkspaceMembership + RoleBinding 判斷；User 本身只代表「是誰」。
 *
 * @property string $id
 * @property string $email
 * @property string $email_normalized
 * @property string $name
 * @property string|null $password_hash
 * @property string|null $mfa_secret_encrypted
 * @property CarbonImmutable|null $mfa_enabled_at
 * @property string|null $mfa_recovery_codes_encrypted
 * @property string $status
 * @property bool $is_platform_operator
 * @property string|null $locale
 * @property CarbonImmutable|null $last_login_at
 */
final class User extends Model implements AuthenticatableContract
{
    use Authenticatable;

    protected $table = 'users';

    protected function casts(): array
    {
        return [
            'mfa_enabled_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'is_platform_operator' => 'boolean',
        ];
    }

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /**
     * 不使用 remember-me cookie（users 表沒有 remember_token 欄位）。
     */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function setRememberToken($value): void {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasMfa(): bool
    {
        return $this->mfa_enabled_at !== null && $this->mfa_secret_encrypted !== null;
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @return HasMany<WorkspaceMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class, 'user_id');
    }
}
