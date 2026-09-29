<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

interface EmbeddingGateway
{
    public function embed(object $connection, object $model, array $texts, ?int $dimensions = null): array;
}
