<?php

declare(strict_types=1);

namespace App\Modules\Ai\Adapters;

use App\Modules\Ai\Contracts\ChatGateway;
use App\Modules\Ai\Contracts\EmbeddingGateway;
use App\Modules\Ai\DTOs\ChatRequest;
use App\Modules\Ai\DTOs\ChatResult;
use App\Support\Database\Records as R;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use App\Support\Security\Egress;
use App\Support\Security\SecretBox;
use Illuminate\Http\Client\ConnectionException;

/** 四種原生協定獨立組裝，不將 Gemini/Anthropic 偷換為 OpenAI。 */
final class NativeHttpAdapter implements ChatGateway, EmbeddingGateway
{
    public function __construct(private readonly Egress $egress, private readonly SecretBox $secrets) {}

    private function http(object $connection, string $path, array $body, int $timeout = 45): array
    {
        $base = rtrim($connection->base_url, '/');
        if (str_ends_with($base, '/v1') && str_starts_with($path, '/v1/')) {
            $path = substr($path, 3);
        }
        $url = $base.$path;
        $key = $connection->secret_encrypted ? $this->secrets->decrypt($connection->secret_encrypted, 'provider:'.$connection->id) : '';
        $http = $this->egress->request($url, $timeout)->acceptJson();
        $http = match ($connection->protocol) {
            'anthropic_messages' => $http->withHeaders(['x-api-key' => $key, 'anthropic-version' => $connection->api_version ?? '2023-06-01']), 'gemini_generate_content' => $http->withHeaders(['x-goog-api-key' => $key]), default => $http->withToken($key)
        };
        try {
            $response = $http->post($url, $body);
        } catch (ConnectionException) {
            throw new ApiException(ErrorCode::TemporarilyUnavailable, null, ['reason' => 'provider_timeout']);
        }
        if (! $response->successful()) {
            throw new ApiException($response->status() === 429 ? ErrorCode::RateLimited : ErrorCode::TemporarilyUnavailable, null, ['reason' => 'provider_http_'.$response->status()]);
        }
        $result = $response->json();
        if (! is_array($result)) {
            throw new ApiException(ErrorCode::TemporarilyUnavailable, null, ['reason' => 'provider_invalid_json']);
        }

        return $result;
    }

    public function generate(ChatRequest $r): ChatResult
    {
        if (! (R::json($r->model->capabilities)['text'] ?? false)) {
            throw new ApiException(ErrorCode::CapabilityUnsupported);
        }
        $protocol = $r->connection->protocol;
        $model = $r->model->external_model_id;
        if ($protocol === 'openai_chat_completions') {
            $data = $this->http($r->connection, '/v1/chat/completions', ['model' => $model, 'messages' => [['role' => 'system', 'content' => $r->system], ...$r->messages], 'max_tokens' => $r->maxOutputTokens], $r->timeout);
            $text = $data['choices'][0]['message']['content'] ?? '';
            $finish = $data['choices'][0]['finish_reason'] ?? 'unknown';
            if (isset($data['choices'][0]['message']['refusal']) && $data['choices'][0]['message']['refusal']) {
                $finish = 'refused';
            }
            $input = $data['usage']['prompt_tokens'] ?? null;
            $output = $data['usage']['completion_tokens'] ?? null;
        } elseif ($protocol === 'openai_responses') {
            $data = $this->http($r->connection, '/v1/responses', ['model' => $model, 'instructions' => $r->system, 'input' => $r->messages, 'max_output_tokens' => $r->maxOutputTokens], $r->timeout);
            $text = '';
            $finish = ($data['status'] ?? '') === 'completed' ? 'stop' : 'incomplete';
            foreach ($data['output'] ?? [] as $item) {
                foreach ($item['content'] ?? [] as $part) {
                    if (($part['type'] ?? '') === 'output_text') {
                        $text .= $part['text'];
                    } if (($part['type'] ?? '') === 'refusal') {
                        $finish = 'refused';
                    }
                }
            }
            $input = $data['usage']['input_tokens'] ?? null;
            $output = $data['usage']['output_tokens'] ?? null;
        } elseif ($protocol === 'anthropic_messages') {
            $data = $this->http($r->connection, '/v1/messages', ['model' => $model, 'system' => $r->system, 'messages' => $r->messages, 'max_tokens' => $r->maxOutputTokens], $r->timeout);
            $text = '';
            foreach ($data['content'] ?? [] as $part) {
                if (($part['type'] ?? '') === 'text') {
                    $text .= $part['text'];
                }
            }
            $finish = match ($data['stop_reason'] ?? '') {
                'end_turn', 'stop_sequence' => 'stop', 'max_tokens' => 'length', default => 'unknown'
            };
            $input = $data['usage']['input_tokens'] ?? null;
            $output = $data['usage']['output_tokens'] ?? null;
        } else {
            $contents = array_map(fn ($m) => ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $m['content']]]], $r->messages);
            $data = $this->http($r->connection, '/v1beta/models/'.rawurlencode($model).':generateContent', ['systemInstruction' => ['parts' => [['text' => $r->system]]], 'contents' => $contents, 'generationConfig' => ['maxOutputTokens' => $r->maxOutputTokens] + (preg_match('/^gemini-3.*flash/', $model) ? ['thinkingConfig' => ['thinkingLevel' => 'low']] : [])], $r->timeout);
            $text = '';
            foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
                if (! ($part['thought'] ?? false)) {
                    $text .= $part['text'] ?? '';
                }
            }
            $finish = match ($data['candidates'][0]['finishReason'] ?? '') {
                'STOP' => 'stop', 'MAX_TOKENS' => 'length', 'SAFETY', 'RECITATION' => 'refused', default => 'unknown'
            };
            $input = $data['usageMetadata']['promptTokenCount'] ?? null;
            $output = $data['usageMetadata']['candidatesTokenCount'] ?? null;
        }
        if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8')) {
            throw new ApiException(ErrorCode::TemporarilyUnavailable);
        }

        return new ChatResult($text, $finish, $input, $output, $input === null || $output === null ? 'unavailable' : 'known');
    }

    public function embed(object $connection, object $model, array $texts, ?int $dimensions = null): array
    {
        if (! (R::json($model->capabilities)['embeddings'] ?? false) || $connection->protocol === 'anthropic_messages') {
            throw new ApiException(ErrorCode::CapabilityUnsupported);
        }
        if ($connection->protocol === 'gemini_generate_content') {
            $vectors = [];
            foreach ($texts as $text) {
                $data = $this->http($connection, '/v1beta/models/'.rawurlencode($model->external_model_id).':embedContent', ['model' => 'models/'.$model->external_model_id, 'content' => ['parts' => [['text' => $text]]]] + ($dimensions ? ['outputDimensionality' => $dimensions] : []));
                $vectors[] = $data['embedding']['values'] ?? [];
            }
        } else {
            // 相容 gateway 未必支援 dimensions；profile 驗證實際維度，不默默截斷。
            $data = $this->http($connection, '/v1/embeddings', ['model' => $model->external_model_id, 'input' => $texts]);
            $items = $data['data'] ?? [];
            usort($items, fn ($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));
            $vectors = array_column($items, 'embedding');
        }
        if (count($vectors) !== count($texts)) {
            throw new ApiException(ErrorCode::TemporarilyUnavailable, null, ['reason' => 'embedding_count']);
        }
        foreach ($vectors as $vector) {
            if (! is_array($vector) || count($vector) === 0 || ($dimensions !== null && count($vector) !== $dimensions)) {
                throw new ApiException(ErrorCode::ValidationFailed, null, ['reason' => 'embedding_dimensions']);
            }
            foreach ($vector as $float) {
                if (! is_numeric($float) || ! is_finite((float) $float)) {
                    throw new ApiException(ErrorCode::ValidationFailed, null, ['reason' => 'embedding_non_finite']);
                }
            }
        }

        return $vectors;
    }
}
