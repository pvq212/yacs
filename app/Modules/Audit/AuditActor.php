<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Modules\AccessControl\StaffActor;

/**
 * 稽核紀錄中的操作者。
 */
final readonly class AuditActor
{
    public function __construct(
        public string $type,
        public ?string $id,
    ) {}

    public static function staff(StaffActor $actor): self
    {
        return new self('staff', $actor->membershipId());
    }

    public static function system(): self
    {
        return new self('system', null);
    }

    public static function visitor(string $contactId): self
    {
        return new self('visitor', $contactId);
    }

    public static function integration(string $clientId): self
    {
        return new self('integration', $clientId);
    }

    public static function platform(string $userId): self
    {
        return new self('platform', $userId);
    }
}
