<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ContractParityTest extends TestCase
{
    public function test_every_documented_operation_has_the_same_route_method_and_path(): void
    {
        $document = json_decode(file_get_contents(base_path('docs/spec/contracts/openapi.json')), true);
        foreach ($document['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $route = Route::getRoutes()->getByName($operation['operationId']);
                $this->assertNotNull($route, $operation['operationId'].' 未實作');
                $this->assertContains(strtoupper($method), $route->methods(), $operation['operationId']);
                $this->assertSame(ltrim($path, '/'), $route->uri(), $operation['operationId']);
            }
        }
    }
}
