<?php

declare(strict_types=1);

namespace App\Modules\Integrations;

use App\Support\Database\Records as R;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use App\Support\Http\Principal;

final readonly class IntegrationActor implements Principal
{
    public function __construct(public object $client) {}

    public function workspaceId(): string
    {
        return $this->client->workspace_id;
    }

    public function principalScope(): string
    {
        return 'api:'.$this->client->id;
    }

    public function allow(string $scope, ?string $brand = null, ?string $inbox = null): void
    {
        if (! in_array($scope, R::json($this->client->scopes), true)) {
            throw new ApiException(ErrorCode::Forbidden);
        }
        foreach (['brand_scope' => $brand, 'inbox_scope' => $inbox] as $column => $id) {
            if ($id !== null && ! in_array($id, R::json($this->client->$column), true)) {
                throw new ApiException(ErrorCode::Forbidden);
            }
        }
    }
}
