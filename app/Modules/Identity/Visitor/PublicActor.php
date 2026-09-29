<?php

declare(strict_types=1);

namespace App\Modules\Identity\Visitor;

use App\Support\Http\Principal;

final readonly class PublicActor implements Principal
{
    public function __construct(private string $workspace, private string $scope) {}

    public function workspaceId(): string
    {
        return $this->workspace;
    }

    public function principalScope(): string
    {
        return $this->scope;
    }
}
