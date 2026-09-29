<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Modules\Ai\Adapters\LaravelAiAdapter;
use App\Modules\Ai\Autopilot;
use App\Modules\Ai\Contracts\ChatGateway;
use App\Modules\Ai\Contracts\EmbeddingGateway;
use App\Modules\Ai\DTOs\ChatRequest;
use App\Modules\Ai\DTOs\ChatResult;
use App\Modules\Conversations\Assignments;
use App\Modules\Conversations\Conversations;
use App\Modules\Conversations\Messages;
use App\Modules\Identity\Visitor\VisitorActor;
use App\Modules\Identity\Visitor\VisitorSessions;
use App\Modules\Knowledge\KnowledgeIndex;
use App\Modules\Tasks\Tasks;
use App\Modules\Tasks\Watchdog;
use App\Support\Database\Records as R;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\StaffClient;
use Tests\Support\World;
use Tests\TestCase;

final class AutopilotTest extends TestCase
{
    private function fixture(): array
    {
        $world = World::create();
        $brand = $world->brand();
        $inbox = $world->inbox($brand);

        return $world->in(function () use ($world, $inbox, $brand): array {
            $connection = R::id();
            $model = R::id();
            $profile = R::id();
            DB::table('provider_connections')->insert(['id' => $connection, 'workspace_id' => $world->workspace->id, 'name' => 'Fake', 'vendor' => 'test', 'protocol' => 'openai_responses', 'base_url' => 'https://fake.example.test', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('ai_models')->insert(['id' => $model, 'workspace_id' => $world->workspace->id, 'connection_id' => $connection, 'external_model_id' => 'fake-text', 'capabilities' => R::encode(['text' => true]), 'verification_state' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('ai_profiles')->insert(['id' => $profile, 'workspace_id' => $world->workspace->id, 'name' => 'Fake profile', 'chat_model_id' => $model, 'prompt_version' => 'v1', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('inboxes')->where('id', $inbox->id)->update(['ai_mode' => 'auto_reply', 'ai_profile_id' => $profile]);
            $inbox->refresh();
            $kb = R::id();
            $document = R::id();
            $version = R::id();
            DB::table('knowledge_bases')->insert(['id' => $kb, 'workspace_id' => $world->workspace->id, 'brand_id' => $brand->id, 'name' => 'FAQ', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('inbox_knowledge_bases')->insert(['id' => R::id(), 'workspace_id' => $world->workspace->id, 'inbox_id' => $inbox->id, 'knowledge_base_id' => $kb]);
            DB::table('knowledge_documents')->insert(['id' => $document, 'workspace_id' => $world->workspace->id, 'knowledge_base_id' => $kb, 'title' => '退款流程', 'source_type' => 'faq', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('knowledge_versions')->insert(['id' => $version, 'workspace_id' => $world->workspace->id, 'document_id' => $document, 'version_number' => 1, 'visibility' => 'external_answerable', 'locale' => 'zh_TW', 'title' => '退款流程', 'body_text' => '退款請至會員中心申請。', 'content_hash' => hash('sha256', '退款'), 'created_at' => now(), 'updated_at' => now()]);
            app(KnowledgeIndex::class)->index($version);
            DB::table('knowledge_versions')->where('id', $version)->update(['state' => 'published']);
            DB::table('knowledge_documents')->where('id', $document)->update(['published_version_id' => $version, 'status' => 'published']);
            $tokens = app(VisitorSessions::class)->anonymous($inbox, []);
            $actor = new VisitorActor(DB::table('visitor_sessions')->where('id', $tokens['visitor_session_id'])->first());
            $c = app(Conversations::class)->create($actor, []);
            app(Messages::class)->send($actor, $c->id, ['client_message_id' => R::id(), 'body_text' => '退款流程']);
            $run = DB::table('ai_runs')->where('conversation_id', $c->id)->first();
            DB::table('async_tasks')->where('id', $run->task_id)->update(['not_before' => now()->subSecond()]);

            return [$world, $c, $run, $connection, $model];
        });
    }

    private function fake(\Closure $callback): void
    {
        $this->app->instance(ChatGateway::class, new class($callback) implements ChatGateway
        {
            public function __construct(private \Closure $callback) {}

            public function generate(ChatRequest $request): ChatResult
            {
                ($this->callback)($request);

                return new ChatResult('{"action":"answer","answer":"退款請至會員中心申請。","source_refs":["S1"],"handoff_reason":null}', 'stop', 100, 30, 'known');
            }
        });
    }

    public function test_provider_probe_cannot_verify_a_model_changed_during_the_request(): void
    {
        [$world, , , $connection, $model] = $this->fixture();
        $world->in(fn () => DB::table('ai_models')->where('id', $model)->update(['verification_state' => 'unverified']));
        $this->fake(fn () => DB::table('ai_models')->where('id', $model)->increment('version'));
        $staff = StaffClient::loginAs($this, $world->ownerStaff());
        $staff->post("/api/v1/workspaces/{$world->workspace->id}/provider-connections/$connection/probe", ['model_id' => $model, 'capabilities' => ['text']])->assertConflict();
        $world->in(function () use ($model): void {
            $this->assertSame('unverified', DB::table('ai_models')->where('id', $model)->value('verification_state'));
        });
    }

    public function test_staff_only_knowledge_is_local_and_never_sent_to_chat_or_embedding(): void
    {
        [$world, $conversation, , $connection, $model] = $this->fixture();
        $embedding = new class implements EmbeddingGateway
        {
            public int $calls = 0;

            public function embed(object $connection, object $model, array $texts, ?int $dimensions = null): array
            {
                $this->calls++;
                throw new \RuntimeException('Internal knowledge must not be sent to embeddings.');
            }
        };
        $this->app->instance(EmbeddingGateway::class, $embedding);
        $this->fake(fn () => $this->fail('Internal knowledge must not be sent to chat.'));
        $versionId = $world->in(function () use ($world, $connection, $model): string {
            $version = DB::table('knowledge_versions')->first();
            $profile = R::id();
            DB::table('embedding_profiles')->insert(['id' => $profile, 'workspace_id' => $world->workspace->id, 'name' => 'External embeddings', 'connection_id' => $connection, 'model_id' => $model, 'dimensions' => 3, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('knowledge_bases')->update(['active_embedding_profile_id' => $profile]);
            DB::table('knowledge_versions')->where('id', $version->id)->update(['visibility' => 'staff_only', 'state' => 'draft']);
            app(KnowledgeIndex::class)->index($version->id);
            $this->assertSame('ready', DB::table('knowledge_versions')->where('id', $version->id)->value('state'));
            $this->assertSame(0, DB::table('knowledge_embeddings')->count());

            return $version->id;
        });
        $staff = StaffClient::loginAs($this, $world->ownerStaff());
        $base = '/api/v1/workspaces/'.$world->workspace->id;
        $staff->post("$base/knowledge-versions/$versionId/publish", ['expected_published_version_id' => $versionId])->assertOk();
        $preview = $staff->post("$base/knowledge-search", ['inbox_id' => $conversation->inbox_id, 'query' => '退款', 'mode' => 'staff_assist']);
        $preview->assertOk();
        $this->assertCount(1, $preview->json('data.sources'));
        $this->assertSame(0, $embedding->calls);
        $world->in(function () use ($world, $conversation): void {
            $run = app(Autopilot::class)->enqueue($conversation, 'assist_draft', $world->ownerMembership->id);
            DB::table('async_tasks')->where('id', $run->task_id)->update(['not_before' => now()->subSecond()]);
            app(Tasks::class)->run($run->task_id, $world->workspace->id);
            $this->assertSame('failed', DB::table('ai_runs')->where('id', $run->id)->value('state'));
            $this->assertSame('no_approved_sources', DB::table('ai_runs')->where('id', $run->id)->value('failure_code'));
            $this->assertSame(0, DB::table('ai_attempts')->count());
        });
    }

    public function test_approved_answer_publishes_once_and_is_cited(): void
    {
        [$world, $c, $run] = $this->fixture();
        $this->fake(fn () => null);
        $world->in(function () use ($world, $c, $run): void {
            app(Tasks::class)->run($run->task_id, $world->workspace->id);
            app(Tasks::class)->run($run->task_id, $world->workspace->id);
            $this->assertSame('published', DB::table('ai_runs')->where('id', $run->id)->value('state'));
            $this->assertSame(1, DB::table('messages')->where('conversation_id', $c->id)->where('author_type', 'ai')->count());
            $this->assertSame('S1', R::json(DB::table('messages')->where('ai_run_id', $run->id)->value('metadata'))['citations'][0]['label']);
        });
    }

    public function test_late_ai_cannot_publish_after_human_claim(): void
    {
        [$world, $c, $run] = $this->fixture();
        $this->fake(fn () => DB::transaction(fn () => app(Assignments::class)->assign(DB::table('conversations')->where('id', $c->id)->lockForUpdate()->first(), $world->ownerMembership->id, $world->ownerMembership->id)));
        $world->in(function () use ($world, $c, $run): void {
            app(Tasks::class)->run($run->task_id, $world->workspace->id);
            $this->assertSame(0, DB::table('messages')->where('conversation_id', $c->id)->where('author_type', 'ai')->count());
            $this->assertSame('human', DB::table('conversations')->where('id', $c->id)->value('handling_mode'));
        });
    }

    public function test_expired_lease_hands_off_without_publication(): void
    {
        [$world, $c, $run] = $this->fixture();
        $this->fake(fn () => DB::table('async_tasks')->where('id', $run->task_id)->update(['lease_expires_at' => now()->subSecond()]));
        $world->in(function () use ($world, $c, $run): void {
            app(Tasks::class)->run($run->task_id, $world->workspace->id);
            $this->assertSame('human_queue', DB::table('conversations')->where('id', $c->id)->value('handling_mode'));
            $this->assertSame(0, DB::table('messages')->where('ai_run_id', $run->id)->count());
        });
    }

    public function test_watchdog_hands_off_even_without_queue_worker(): void
    {
        [$world, $c, $run] = $this->fixture();
        $world->in(function () use ($c, $run): void {
            DB::table('ai_runs')->where('id', $run->id)->update(['deadline_at' => now()->subSecond()]);
            app(Watchdog::class)->tick();
            $this->assertSame('human_queue', DB::table('conversations')->where('id', $c->id)->value('handling_mode'));
            $this->assertSame('handed_off', DB::table('ai_runs')->where('id', $run->id)->value('state'));
        });
    }

    public function test_changed_knowledge_generation_prevents_old_answer(): void
    {
        [$world, $c, $run] = $this->fixture();
        $this->fake(fn () => DB::table('workspaces')->where('id', $world->workspace->id)->increment('knowledge_generation'));
        $world->in(function () use ($world, $c, $run): void {
            app(Tasks::class)->run($run->task_id, $world->workspace->id);
            $this->assertSame(0, DB::table('messages')->where('ai_run_id', $run->id)->count());
            $this->assertSame('human_queue', DB::table('conversations')->where('id', $c->id)->value('handling_mode'));
        });
    }

    public function test_same_connection_fallback_is_bounded_and_records_failed_attempt_usage_as_unknown(): void
    {
        [$world, $c, $run, $connection, $model] = $this->fixture();
        $backup = R::id();
        $world->in(function () use ($backup, $world, $connection, $run): void {
            DB::table('ai_models')->insert(['id' => $backup, 'workspace_id' => $world->workspace->id, 'connection_id' => $connection, 'external_model_id' => 'backup', 'capabilities' => R::encode(['text' => true]), 'verification_state' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('ai_profiles')->where('id', $run->ai_profile_id)->update(['fallback_policy' => R::encode(['allowed_model_ids' => [$backup]])]);
        });
        $this->fake(function (ChatRequest $request) use ($model): void {
            if ($request->model->id === $model) {
                throw new \RuntimeException('provider_unavailable');
            }
        });
        $world->in(function () use ($run, $world): void {
            app(Tasks::class)->run($run->task_id, $world->workspace->id);
            $this->assertSame('published', DB::table('ai_runs')->where('id', $run->id)->value('state'));
            $this->assertSame(2, DB::table('ai_attempts')->where('run_id', $run->id)->count());
            $failed = DB::table('ai_attempts')->where('run_id', $run->id)->where('attempt_no', 1)->first();
            $this->assertNull($failed->input_tokens);
            $this->assertSame('provider_failed', $failed->error_code);
        });
    }

    public function test_daily_budget_exhaustion_hands_off_without_sending_provider_request(): void
    {
        [$world, $c, $run] = $this->fixture();
        config(['yacs.ai.daily_call_budget' => 0]);
        $this->fake(function (): void {
            $this->fail('預算耗盡不得呼叫模型');
        });
        $world->in(function () use ($run, $world, $c): void {
            app(Tasks::class)->run($run->task_id, $world->workspace->id);
            $this->assertSame('human_queue', DB::table('conversations')->where('id', $c->id)->value('handling_mode'));
            $this->assertSame(0, DB::table('ai_attempts')->where('run_id', $run->id)->count());
        });
    }

    public function test_sdk_adapter_keeps_missing_usage_unknown_and_uses_responses_protocol(): void
    {
        config(['yacs.egress.allow_insecure_for_testing' => true]);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['id' => 'resp_test', 'object' => 'response', 'status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'OK', 'annotations' => []]]]]])]);
        $connection = (object) ['id' => R::id(), 'protocol' => 'openai_responses', 'base_url' => 'https://fake.example.test', 'secret_encrypted' => null, 'api_version' => null];
        $model = (object) ['external_model_id' => 'fake-model', 'capabilities' => '{"text":true}'];
        $result = app(LaravelAiAdapter::class)->generate(new ChatRequest($connection, $model, 'Reply OK.', [['role' => 'user', 'content' => 'Test']], 32));
        $this->assertSame('OK', $result->text);
        $this->assertSame('unavailable', $result->usageState);
        $this->assertNull($result->inputTokens);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/responses') && $r['max_output_tokens'] === 32);
    }
}
