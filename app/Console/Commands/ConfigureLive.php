<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Ai\Contracts\ChatGateway;
use App\Modules\Ai\DTOs\ChatRequest;
use App\Support\Database\Records as R;
use App\Support\Security\SecretBox;
use App\Support\Tenancy\TenantDatabase;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ConfigureLive extends Command
{
    protected $signature = 'yacs:configure-live';

    protected $description = '將已授權的本機 AI 測試設定加密匯入示範工作空間並驗證文字模型';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing') || ! is_file(base_path('.env.live')) || ! Storage::disk('private')->exists('demo-access.json')) {
            $this->error('需要本機示範環境與 .env.live');

            return 1;
        }
        $env = Dotenv::parse(file_get_contents(base_path('.env.live')));
        $demo = json_decode(Storage::disk('private')->get('demo-access.json'), true);
        $report = [];
        app(TenantDatabase::class)->withinWorkspace($demo['workspace_id'], function () use ($env, $demo, &$report): void {
            $ids = [];
            foreach (['gemini' => 'gemini_generate_content', 'gpt' => 'openai_responses', 'claude' => 'anthropic_messages'] as $name => $protocol) {
                $connection = DB::table('provider_connections')->where('name', '測試 Gateway · '.$name)->first();
                if ($connection === null) {
                    $id = R::id();
                    DB::table('provider_connections')->insert(['id' => $id, 'workspace_id' => $demo['workspace_id'], 'name' => '測試 Gateway · '.$name, 'vendor' => 'private_gateway', 'protocol' => $protocol, 'base_url' => $env['YACS_LIVE_AI_BASE_URL'], 'secret_encrypted' => app(SecretBox::class)->encrypt($env['YACS_LIVE_AI_API_KEY'], 'provider:'.$id), 'created_at' => now(), 'updated_at' => now()]);
                    $connection = DB::table('provider_connections')->where('id', $id)->first();
                }
                $model = DB::table('ai_models')->where('connection_id', $connection->id)->where('external_model_id', $env['YACS_LIVE_AI_MODEL_'.strtoupper($name)])->first();
                if ($model === null) {
                    $id = R::id();
                    DB::table('ai_models')->insert(['id' => $id, 'workspace_id' => $demo['workspace_id'], 'connection_id' => $connection->id, 'external_model_id' => $env['YACS_LIVE_AI_MODEL_'.strtoupper($name)], 'capabilities' => R::encode(['text' => true]), 'created_at' => now(), 'updated_at' => now()]);
                    $model = DB::table('ai_models')->where('id', $id)->first();
                }
                $ids[$name] = $model->id;
                if ($model->verification_state === 'verified') {
                    $report[$name] = ['verified' => true, 'cached' => true];

                    continue;
                }
                try {
                    $result = app(ChatGateway::class)->generate(new ChatRequest($connection, $model, 'Reply exactly OK.', [['role' => 'user', 'content' => 'OK']], $name === 'gemini' ? 512 : 64));
                    $verified = $result->finishReason === 'stop' && trim($result->text) !== '';
                } catch (\Throwable) {
                    $verified = false;
                }
                DB::table('ai_models')->where('id', $model->id)->update(['verification_state' => $verified ? 'verified' : 'failed', 'verified_at' => $verified ? now() : null]);
                DB::table('provider_connections')->where('id', $connection->id)->update(['health' => $verified ? 'healthy' : 'failing', 'last_probe_at' => now()]);
                $report[$name] = ['verified' => $verified, 'model' => $model->external_model_id];
            }
            $connection = DB::table('provider_connections')->where('name', '測試 Gateway · gemini')->first();
            if (! DB::table('ai_models')->where('external_model_id', $env['YACS_LIVE_AI_MODEL_EMBEDDING'])->exists()) {
                DB::table('ai_models')->insert(['id' => R::id(), 'workspace_id' => $demo['workspace_id'], 'connection_id' => $connection->id, 'external_model_id' => $env['YACS_LIVE_AI_MODEL_EMBEDDING'], 'capabilities' => R::encode(['embeddings' => true]), 'verification_state' => 'failed', 'created_at' => now(), 'updated_at' => now()]);
            }
            $profile = DB::table('ai_profiles')->where('name', '預設客服助理')->first();
            if ($profile === null) {
                $id = R::id();
                DB::table('ai_profiles')->insert(['id' => $id, 'workspace_id' => $demo['workspace_id'], 'name' => '預設客服助理', 'chat_model_id' => $ids['gpt'], 'prompt_version' => 'zh_TW-v1', 'limits' => R::encode(['max_output_tokens' => 1024]), 'created_at' => now(), 'updated_at' => now()]);
                $profile = DB::table('ai_profiles')->where('id', $id)->first();
            }
            DB::table('inboxes')->where('id', $demo['inbox_id'])->update(['ai_profile_id' => $profile->id, 'ai_mode' => 'assist_only']);
        });
        Storage::disk('private')->put('configured-ai.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line(json_encode($report, JSON_UNESCAPED_UNICODE));

        return 0;
    }
}
