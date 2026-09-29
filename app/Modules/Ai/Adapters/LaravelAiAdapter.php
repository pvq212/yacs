<?php

declare(strict_types=1);

namespace App\Modules\Ai\Adapters;

use App\Modules\Ai\Contracts\ChatGateway;
use App\Modules\Ai\DTOs\ChatRequest;
use App\Modules\Ai\DTOs\ChatResult;
use App\Support\Database\Records as R;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use App\Support\Security\SecretBox;
use Laravel\Ai\AiManager;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\AnthropicProvider;
use Laravel\Ai\Providers\OpenAiCompatibleProvider;
use Laravel\Ai\Providers\OpenAiProvider;

/** 每次呼叫隔離 SDK provider，避免 Octane 的全域 config／provider cache 帶走租戶金鑰。 */
final class LaravelAiAdapter implements ChatGateway
{
    public function generate(ChatRequest $r): ChatResult
    {
        // SDK 1.0 的 Gemini 使用 Interactions；明確設定 generateContent 時走同協定的原生 adapter。
        if ($r->connection->protocol === 'gemini_generate_content') {
            return app(NativeHttpAdapter::class)->generate($r);
        }
        if (! (R::json($r->model->capabilities)['text'] ?? false)) {
            throw new ApiException(ErrorCode::CapabilityUnsupported);
        }
        [$driver, $gateway, $suffix] = match ($r->connection->protocol) {
            'openai_responses' => ['openai', Sdk\ResponsesGateway::class, '/v1'],
            'openai_chat_completions' => ['openai-compatible', Sdk\ChatCompletionsGateway::class, '/v1'],
            'anthropic_messages' => ['anthropic', Sdk\AnthropicGateway::class, '/v1'],
            'gemini_generate_content' => ['gemini', Sdk\GeminiGateway::class, '/v1beta'],
            default => throw new ApiException(ErrorCode::CapabilityUnsupported),
        };
        $url = rtrim($r->connection->base_url, '/');
        if (! str_ends_with($url, $suffix)) {
            $url .= $suffix;
        }
        $key = $r->connection->secret_encrypted ? app(SecretBox::class)->decrypt($r->connection->secret_encrypted, 'provider:'.$r->connection->id) : '';
        $manager = new AiManager(app());
        $provider = $manager->build(['name' => 'yacs_'.R::id(), 'driver' => $driver, 'key' => $key, 'url' => $url, 'version' => $r->connection->api_version ?? '2023-06-01']);
        if (! ($provider instanceof OpenAiProvider || $provider instanceof OpenAiCompatibleProvider || $provider instanceof AnthropicProvider)) {
            throw new \LogicException('Unsupported SDK provider.');
        }
        $provider->useTextGateway(new $gateway(app('events')));
        $history = $r->messages;
        $last = array_pop($history);
        $messages = array_map(fn ($m) => new Message($m['role'], $m['content']), $history);
        $agent = new class($r->system, $messages, []) extends AnonymousAgent
        {
            public int $outputLimit = 1024;

            public function maxTokens(): int
            {
                return $this->outputLimit;
            }

            public function maxSteps(): int
            {
                return 1;
            }
        };
        $agent->outputLimit = $r->maxOutputTokens;
        try {
            $response = $provider->prompt(new AgentPrompt($agent, $last['content'] ?? '', [], $provider, $r->model->external_model_id, $r->timeout));
        } catch (\Throwable) {
            throw new ApiException(ErrorCode::TemporarilyUnavailable, null, ['reason' => 'sdk_provider_failed']);
        }
        $raw = $response->raw?->json() ?? [];
        $usage = $raw['usage'] ?? $raw['usageMetadata'] ?? null;
        $known = is_array($usage) && (isset($usage['input_tokens']) || isset($usage['prompt_tokens']) || isset($usage['promptTokenCount'])) && (isset($usage['output_tokens']) || isset($usage['completion_tokens']) || isset($usage['candidatesTokenCount']));

        return new ChatResult($response->text, $response->steps->last()?->finishReason->value ?? 'unknown', $known ? $response->usage->inputTokens : null, $known ? $response->usage->outputTokens : null, $known ? 'known' : 'unavailable');
    }
}
