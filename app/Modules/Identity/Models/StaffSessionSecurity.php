<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Support\Database\Model;
use Carbon\CarbonImmutable;

/**
 * Staff 登入 session 的安全狀態（MFA 完成時間、撤銷）。
 *
 * `id` 同時作為 realtime fanout 的 staff session 識別（channel `private-staff.{id}.{membership}`），
 * 不使用 session cookie 原文。
 *
 * @property string $id
 * @property string $user_id
 * @property string $session_id_digest
 * @property int $session_generation
 * @property CarbonImmutable|null $mfa_verified_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $revoked_at
 */
final class StaffSessionSecurity extends Model
{
    protected $table = 'staff_session_security';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'session_generation' => 'integer',
            'mfa_verified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
