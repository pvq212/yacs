<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Ai\Adapters\NativeHttpAdapter;
use App\Modules\Ai\DTOs\ChatRequest;
use App\Support\Database\Records as R;
use App\Support\Http\ApiException;
use App\Support\Security\SecretBox;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** 需操作者明確執行；不在 CI、seed 或排程中自動花費模型費用。 */
final class LiveProbe extends Command
{
    protected $signature = 'yacs:live-probe {--ai} {--r2} {--bucket=} {--only=}';

    protected $description = '讀取 gitignored .env.live，執行一次低輸出量的 AI／R2 真實連線驗證';

    public function handle(): int
    {
        if (! is_file(base_path('.env.live'))) {
            $this->error('缺少 .env.live');

            return 1;
        }
        $env = Dotenv::parse(file_get_contents(base_path('.env.live')));
        $report = Storage::disk('private')->exists('live-probe.json') ? json_decode(Storage::disk('private')->get('live-probe.json'), true) : [];
        $report['checked_at'] = now()->toISOString();
        $failed = false;
        if ($this->option('ai')) {
            foreach (['gemini' => 'gemini_generate_content', 'gpt' => 'openai_responses', 'claude' => 'anthropic_messages', 'embedding' => 'gemini_generate_content'] as $name => $protocol) {
                if ($this->option('only') && ! in_array($name, explode(',', $this->option('only')), true)) {
                    continue;
                }
                $id = R::id();
                $connection = (object) ['id' => $id, 'protocol' => $protocol, 'base_url' => $env['YACS_LIVE_AI_BASE_URL'], 'api_version' => null, 'secret_encrypted' => app(SecretBox::class)->encrypt($env['YACS_LIVE_AI_API_KEY'], 'provider:'.$id)];
                $model = (object) ['external_model_id' => $env['YACS_LIVE_AI_MODEL_'.strtoupper($name)], 'capabilities' => R::encode([$name === 'embedding' ? 'embeddings' : 'text' => true])];
                $start = microtime(true);
                try {
                    if ($name === 'embedding') {
                        $vectors = app(NativeHttpAdapter::class)->embed($connection, $model, ['客服退款流程測試']);
                        $detail = ['dimensions' => count($vectors[0])];
                    } else {
                        $result = app(NativeHttpAdapter::class)->generate(new ChatRequest($connection, $model, 'Reply exactly OK. Do not reason or explain.', [['role' => 'user', 'content' => 'OK']], 512, 45));
                        $detail = ['finish_reason' => $result->finishReason, 'text_received' => trim($result->text) !== '', 'usage_state' => $result->usageState];
                    }
                    $report['ai'][$name] = ['ok' => $name === 'embedding' || ($result->finishReason === 'stop' && trim($result->text) !== ''), 'model' => $model->external_model_id, 'protocol' => $protocol, 'duration_ms' => (int) ((microtime(true) - $start) * 1000)] + $detail;
                } catch (\Throwable $e) {
                    $failed = true;
                    $report['ai'][$name] = ['ok' => false, 'model' => $model->external_model_id, 'error' => $e instanceof ApiException ? $e->errorCode->value : 'connection_failed', 'reason' => $e instanceof ApiException ? ($e->details['reason'] ?? null) : null];
                }
                if (! $report['ai'][$name]['ok']) {
                    $failed = true;
                }
                $this->line(json_encode([$name => $report['ai'][$name]], JSON_UNESCAPED_UNICODE));
            }
        }
        if ($this->option('r2')) {
            $client = new S3Client(['version' => 'latest', 'region' => 'auto', 'endpoint' => $env['YACS_LIVE_R2_ENDPOINT'], 'use_path_style_endpoint' => true, 'credentials' => ['key' => $env['YACS_LIVE_R2_ACCESS_KEY_ID'], 'secret' => $env['YACS_LIVE_R2_SECRET_ACCESS_KEY']], 'http' => ['connect_timeout' => 5, 'timeout' => 15]]);
            $bucket = $this->option('bucket');
            $object = 'yacs-connection-test/'.R::id().'.txt';
            $written = false;
            try {
                if (! $bucket) {
                    throw new \RuntimeException('bucket_required');
                }
                $payload = 'YACS S3 相容性測試 '.R::id();
                $client->putObject(['Bucket' => $bucket, 'Key' => $object, 'Body' => $payload, 'ContentType' => 'text/plain']);
                $written = true;
                if ((string) $client->getObject(['Bucket' => $bucket, 'Key' => $object])['Body'] !== $payload) {
                    throw new \RuntimeException('content_mismatch');
                }
                $client->deleteObject(['Bucket' => $bucket, 'Key' => $object]);
                $written = false;
                $report['r2'] = ['ok' => true, 'bucket' => $bucket, 'put_get_delete' => true];
            } catch (\Throwable $e) {
                $failed = true;
                $report['r2'] = ['ok' => false, 'bucket' => $bucket, 'error' => $e instanceof AwsException ? $e->getAwsErrorCode() : 'bucket_or_connection_failed'];
            } finally {
                if ($written) {
                    try {
                        $client->deleteObject(['Bucket' => $bucket, 'Key' => $object]);
                    } catch (\Throwable) {
                        $report['r2']['cleanup_required_key'] = $object;
                    }
                }
            }
            $this->line(json_encode(['r2' => $report['r2']], JSON_UNESCAPED_UNICODE));
        }
        Storage::disk('private')->put('live-probe.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $failed ? 1 : 0;
    }
}
