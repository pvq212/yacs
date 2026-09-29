<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

interface RerankGateway
{
    public function rank(string $query, array $authorizedSources, int $top): array;
}
