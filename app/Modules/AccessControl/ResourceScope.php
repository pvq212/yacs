<?php

declare(strict_types=1);

namespace App\Modules\AccessControl;

/**
 * 授權判斷時「資源所在的範圍」。
 *
 * 角色授予（role binding）的 scope 如何涵蓋資源：
 *  - workspace：涵蓋 workspace 內所有資源。
 *  - brand：涵蓋 brandId 相同的資源（含該品牌下的收件匣與對話）。
 *  - inbox：涵蓋 inboxId 相同的資源（含該收件匣的對話）。
 *  - team：涵蓋 teamId 相同的資源，或所在收件匣已連結該團隊（inboxTeamIds）。
 *
 * workspace 層級資源（例如角色、API token）使用 `workspace()`，只有 workspace 範圍的授予能涵蓋。
 */
final readonly class ResourceScope
{
    /**
     * @param  list<string>  $inboxTeamIds  資源所在收件匣連結的團隊
     */
    public function __construct(
        public ?string $brandId = null,
        public ?string $inboxId = null,
        public ?string $teamId = null,
        public array $inboxTeamIds = [],
    ) {}

    public static function workspace(): self
    {
        return new self;
    }

    public static function brand(string $brandId): self
    {
        return new self(brandId: $brandId);
    }

    public static function team(string $teamId): self
    {
        return new self(teamId: $teamId);
    }

    public function isCoveredBy(string $scopeType, ?string $scopeId): bool
    {
        return match ($scopeType) {
            'workspace' => true,
            'brand' => $scopeId !== null && $this->brandId === $scopeId,
            'inbox' => $scopeId !== null && $this->inboxId === $scopeId,
            'team' => $scopeId !== null && ($this->teamId === $scopeId || in_array($scopeId, $this->inboxTeamIds, true)),
            default => false,
        };
    }
}
