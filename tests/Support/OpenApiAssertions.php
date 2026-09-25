<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * 以 OpenAPI 契約驗證 HTTP 回應（contract test）。
 *
 * 依 operationId 找到對應的 path/method，確認回應狀態碼已在契約中定義，
 * 並以 JSON Schema 驗證回應 body（additionalProperties: false 會抓出多餘欄位）。
 */
trait OpenApiAssertions
{
    private static ?array $openApiIndex = null;

    private static ?Validator $openApiValidator = null;

    /**
     * @param  TestResponse<Response>  $response
     */
    protected function assertMatchesOpenApi(TestResponse $response, string $operationId): void
    {
        [$path, $method, $operation] = self::openApiOperation($operationId);
        $status = (string) $response->getStatusCode();
        Assert::assertArrayHasKey($status, $operation['responses'], "Status {$status} is not documented for {$operationId}. Body: ".$response->getContent());

        $content = self::resolveResponse($operation['responses'][$status])['content']['application/json']['schema'] ?? null;
        if ($content === null) {
            Assert::assertSame('', (string) $response->getContent(), "{$operationId} {$status} must have an empty body");

            return;
        }

        $pointer = str_replace(['~', '/'], ['~0', '~1'], $path);
        $responsePointer = isset($operation['responses'][$status]['$ref'])
            ? substr($operation['responses'][$status]['$ref'], 1).'/content/application~1json/schema'
            : "/paths/{$pointer}/{$method}/responses/{$status}/content/application~1json/schema";

        $data = json_decode((string) $response->getContent(), false);
        $result = self::openApiValidator()->validate($data, 'https://yacs.test/openapi.json#'.$responsePointer);
        if (! $result->isValid()) {
            $errors = (new ErrorFormatter)->format($result->error(), true);
            Assert::fail("Response of {$operationId} ({$status}) violates OpenAPI:\n".json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\nBody: ".$response->getContent());
        }
        Assert::assertTrue(true);
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    protected static function openApiOperation(string $operationId): array
    {
        $index = self::openApiIndex();
        Assert::assertArrayHasKey($operationId, $index, "Unknown operationId {$operationId}");

        return $index[$operationId];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    protected static function openApiIndex(): array
    {
        if (self::$openApiIndex === null) {
            $doc = self::openApiDocument(true);
            self::$openApiIndex = [];
            foreach ($doc['paths'] as $path => $item) {
                foreach ($item as $method => $operation) {
                    if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                        self::$openApiIndex[$operation['operationId']] = [$path, $method, $operation];
                    }
                }
            }
        }

        return self::$openApiIndex;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private static function resolveResponse(array $response): array
    {
        if (! isset($response['$ref'])) {
            return $response;
        }
        $doc = self::openApiDocument(true);
        $name = substr($response['$ref'], strlen('#/components/responses/'));

        return $doc['components']['responses'][$name];
    }

    /**
     * 讀取由 scripts/openapi-build.py 產生的 openapi.json（CI 會檢查與 YAML 同步）。
     */
    protected static function openApiDocument(bool $assoc): mixed
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/docs/spec/contracts/openapi.json'), $assoc, flags: JSON_THROW_ON_ERROR);
    }

    private static function openApiValidator(): Validator
    {
        if (self::$openApiValidator === null) {
            $doc = self::openApiDocument(false);
            self::$openApiValidator = new Validator;
            self::$openApiValidator->setMaxErrors(10);
            self::$openApiValidator->resolver()->registerRaw($doc, 'https://yacs.test/openapi.json');
        }

        return self::$openApiValidator;
    }
}
