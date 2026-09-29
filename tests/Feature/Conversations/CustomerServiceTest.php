<?php

declare(strict_types=1);

namespace Tests\Feature\Conversations;

use App\Modules\Tasks\Tasks;
use App\Support\Database\Records as R;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\StaffClient;
use Tests\Support\World;
use Tests\TestCase;

final class CustomerServiceTest extends TestCase
{
    private function visitor(string $method, string $path, array $body = [], ?string $token = null, ?string $key = null): TestResponse
    {
        return $this->call($method, '/api/v1/widget/'.$path, [], [], [], array_filter(['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => $token ? 'Bearer '.$token : null, 'HTTP_IDEMPOTENCY_KEY' => $key ?? (string) Str::uuid(), 'HTTP_ORIGIN' => 'https://host.example.test']), json_encode((object) $body));
    }

    private function setupWorld(): array
    {
        $world = World::create();
        $brand = $world->brand('商店');
        $inbox = $world->inbox($brand);
        $world->in(fn () => DB::table('inbox_origins')->insert(['id' => R::id(), 'workspace_id' => $world->workspace->id, 'inbox_id' => $inbox->id, 'origin' => 'https://host.example.test', 'created_at' => now()]));
        $response = $this->visitor('POST', 'bootstrap', ['inbox_key' => $inbox->public_key, 'parent_origin' => 'https://host.example.test']);
        $response->assertCreated();
        $this->assertMatchesOpenApi($response, 'bootstrapWidget');

        return [$world, $inbox, $response->json('data.session')];
    }

    public function test_complete_human_flow_and_public_note_isolation(): void
    {
        [$world, $inbox, $session] = $this->setupWorld();
        $token = $session['access_token'];
        $staff = StaffClient::loginAs($this, $world->ownerStaff());
        $base = '/api/v1/workspaces/'.$world->workspace->id;
        $created = $this->visitor('POST', 'conversations', [], $token);
        $created->assertCreated();
        $this->assertMatchesOpenApi($created, 'createVisitorConversation');
        $id = $created->json('data.id');
        $body = ['body_text' => '請問退款流程？', 'client_message_id' => (string) Str::uuid()];
        $sent = $this->visitor('POST', "conversations/$id/messages", $body, $token);
        $sent->assertCreated();
        $this->assertMatchesOpenApi($sent, 'sendVisitorMessage');
        $retry = $this->visitor('POST', "conversations/$id/messages", $body, $token);
        $retry->assertCreated();
        $this->assertSame($sent->json('data.id'), $retry->json('data.id'));
        $current = $staff->get("$base/conversations/$id");
        $current->assertOk();
        $claim = $staff->post("$base/conversations/$id/claim", ['expected_version' => $current->json('data.version')]);
        $claim->assertOk();
        $this->assertMatchesOpenApi($claim, 'staffClaim');
        $staff->post("$base/conversations/$id/claim", ['expected_version' => $current->json('data.version')])->assertConflict();
        $note = $staff->post("$base/conversations/$id/messages", ['visibility' => 'internal', 'body_text' => '內部機密備註', 'client_message_id' => (string) Str::uuid(), 'expected_version' => $claim->json('data.version')]);
        $note->assertCreated();
        $reply = $staff->post("$base/conversations/$id/messages", ['visibility' => 'public', 'body_text' => '請到會員中心申請退款。', 'client_message_id' => (string) Str::uuid(), 'expected_version' => $claim->json('data.version'), 'after_send' => 'waiting_customer']);
        $reply->assertCreated();
        $snapshot = $this->visitor('GET', "conversations/$id/snapshot", [], $token);
        $snapshot->assertOk();
        $this->assertMatchesOpenApi($snapshot, 'snapshotVisitorConversation');
        $this->assertStringNotContainsString('內部機密', $snapshot->getContent());
        $this->assertCount(2, $snapshot->json('data.messages'));
        $events = $this->visitor('GET', "conversations/$id/events?after_seq=0", [], $token);
        $events->assertOk();
        $this->assertMatchesOpenApi($events, 'listVisitorEvents');
        $this->assertStringNotContainsString('內部機密', $events->getContent());
        $current = $staff->get("$base/conversations/$id");
        $resolved = $staff->post("$base/conversations/$id/resolve", ['expected_version' => $current->json('data.version'), 'resolution_code' => 'answered']);
        $resolved->assertOk();
        $this->visitor('POST', "conversations/$id/csat", ['resolution_cycle' => 1, 'score' => 5], $token)->assertNoContent();
        $this->visitor('POST', "conversations/$id/messages", ['body_text' => '還有一個問題', 'client_message_id' => (string) Str::uuid()], $token)->assertCreated();
        $this->assertSame('human_queue', $this->visitor('GET', "conversations/$id", [], $token)->json('data.handling_mode'));
    }

    public function test_refresh_replay_revokes_family_and_foreign_contact_is_denied(): void
    {
        [$world, $inbox, $session] = $this->setupWorld();
        $conversation = $this->visitor('POST', 'conversations', [], $session['access_token'])->json('data.id');
        $second = $this->visitor('POST', 'bootstrap', ['inbox_key' => $inbox->public_key, 'parent_origin' => 'https://host.example.test'])->json('data.session');
        $this->visitor('GET', 'conversations/'.$conversation, [], $second['access_token'])->assertNotFound();
        $refresh = $this->visitor('POST', 'token/refresh', ['refresh_token' => $session['refresh_token']]);
        $refresh->assertOk();
        $this->assertMatchesOpenApi($refresh, 'refreshVisitorToken');
        $this->visitor('GET', 'conversations', [], $session['access_token'])->assertUnauthorized();
        $this->visitor('POST', 'token/refresh', ['refresh_token' => $session['refresh_token']])->assertUnauthorized();
        $this->visitor('GET', 'conversations', [], $refresh->json('data.access_token'))->assertUnauthorized();
        $this->visitor('POST', 'bootstrap', ['inbox_key' => $inbox->public_key, 'parent_origin' => 'https://evil.example.test'])->assertForbidden();
    }

    public function test_knowledge_publication_and_ai_draft_are_staff_only(): void
    {
        [$world, $inbox, $session] = $this->setupWorld();
        $staff = StaffClient::loginAs($this, $world->ownerStaff());
        $base = '/api/v1/workspaces/'.$world->workspace->id;
        $kb = $staff->post("$base/knowledge-bases", ['brand_id' => $inbox->brand_id, 'name' => 'FAQ', 'locale' => 'zh_TW', 'status' => 'active', 'inbox_ids' => [$inbox->id]]);
        $kb->assertCreated();
        $this->assertMatchesOpenApi($kb, 'createKnowledgeBase');
        $document = $staff->post("$base/knowledge-documents", ['knowledge_base_id' => $kb->json('data.id'), 'title' => '退款流程', 'source_type' => 'faq']);
        $document->assertCreated();
        $version = $staff->post("$base/knowledge-documents/{$document->json('data.id')}/versions", ['body_text' => '退款請在七日內到會員中心申請。', 'visibility' => 'external_answerable', 'locale' => 'zh_TW', 'faq_aliases' => ['返還款項']]);
        $version->assertCreated();
        $index = $staff->post("$base/knowledge-versions/{$version->json('data.id')}/index", []);
        $index->assertAccepted();
        $world->in(fn () => app(Tasks::class)->run($index->json('data.id'), $world->workspace->id));
        $publish = $staff->post("$base/knowledge-versions/{$version->json('data.id')}/publish", ['expected_published_version_id' => null]);
        $publish->assertOk();
        $this->assertMatchesOpenApi($publish, 'publishKnowledgeVersion');
        $faq = $this->visitor('GET', 'faqs', [], $session['access_token']);
        $faq->assertOk();
        $this->assertCount(1, $faq->json('data'));
        $search = $staff->post("$base/knowledge-search", ['inbox_id' => $inbox->id, 'query' => '退款', 'mode' => 'external_preview']);
        $search->assertOk();
        $this->assertMatchesOpenApi($search, 'previewKnowledgeSearch');
        $aliases = $staff->post("$base/knowledge-search", ['inbox_id' => $inbox->id, 'query' => '請問返還款項的辦法', 'mode' => 'external_preview']);
        $aliases->assertOk();
        $this->assertCount(1, $aliases->json('data.sources'));
        $staff->post("$base/knowledge-documents/{$document->json('data.id')}/unpublish", ['expected_published_version_id' => $version->json('data.id')])->assertNoContent();
        $this->assertCount(0, $this->visitor('GET', 'faqs', [], $session['access_token'])->json('data'));
        $this->assertCount(0, $staff->post("$base/knowledge-search", ['inbox_id' => $inbox->id, 'query' => '返還款項', 'mode' => 'external_preview'])->json('data.sources'));
    }
}
