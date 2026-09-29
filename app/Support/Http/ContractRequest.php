<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Validation\Validator as LaravelValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/** OpenAPI 是輸入契約的唯一來源；所有新端點同時檢查型別、未知欄位與 anyOf。 */
final class ContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function withValidator(LaravelValidator $validator): void
    {
        $validator->after(function (LaravelValidator $validator): void {
            $doc = json_decode((string) file_get_contents(base_path('docs/spec/contracts/openapi.json')));
            $operation = null;
            foreach ($doc->paths as $item) {
                foreach ($item as $candidate) {
                    if (($candidate->operationId ?? null) === $this->route()?->getName()) {
                        $operation = $candidate;
                        break 2;
                    }
                }
            }
            if ($operation === null) {
                throw new \LogicException('Missing API contract.');
            }
            foreach ($operation->parameters ?? [] as $parameter) {
                if (isset($parameter->{'$ref'})) {
                    $parameter = $doc->components->parameters->{basename($parameter->{'$ref'})};
                }
                if (($parameter->in ?? '') === 'path' && ($parameter->schema->format ?? '') === 'uuid' && ! Str::isUuid((string) $this->route($parameter->name))) {
                    throw new ApiException(ErrorCode::NotFound);
                }
            }
            $schema = $operation->requestBody->content->{'application/json'}->schema ?? null;
            $data = json_decode($this->getContent() ?: '{}');
            if ($this->isMethod('GET')) {
                $schema = (object) ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false];
                $data = (object) $this->query();
                foreach ($operation->parameters ?? [] as $parameter) {
                    if (isset($parameter->{'$ref'})) {
                        $parameter = $doc->components->parameters->{basename($parameter->{'$ref'})};
                    }
                    if (($parameter->in ?? '') !== 'query') {
                        continue;
                    }
                    $schema->properties->{$parameter->name} = $parameter->schema;
                    if ($parameter->required ?? false) {
                        $schema->required ??= [];
                        $schema->required[] = $parameter->name;
                    }
                    if (($parameter->schema->type ?? '') === 'integer' && isset($data->{$parameter->name}) && ctype_digit((string) $data->{$parameter->name})) {
                        $data->{$parameter->name} = (int) $data->{$parameter->name};
                    }
                }
            }
            $schema ??= (object) ['type' => 'object', 'additionalProperties' => false];
            $check = new Validator;
            $doc->components->schemas->RuntimeRequest = $schema;
            $check->resolver()->registerRaw($doc, 'https://yacs.local/contract');
            $result = $check->validate($data, 'https://yacs.local/contract#/components/schemas/RuntimeRequest');
            if (! $result->isValid()) {
                foreach ((new ErrorFormatter)->format($result->error()) as $path => $errors) {
                    $validator->errors()->add($path ?: 'body', implode('; ', $errors));
                }
            }
        });
    }

    /** 僅回傳已經完整 JSON Schema 驗證的輸入，不混入 route/query。 */
    public function payload(): array
    {
        return $this->isMethod('GET') ? $this->query() : $this->json()->all();
    }
}
