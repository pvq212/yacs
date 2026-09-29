<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Modules\Integrations\ChannelDeliveries;
use App\Modules\Integrations\Webhooks;
use App\Support\Database\Records as R;
use App\Support\Security\SecretBox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\StaffClient;
use Tests\Support\World;
use Tests\TestCase;

final class ChannelTest extends TestCase
{
    private function hook(string $key, array $body, string $secret, bool $valid = true): TestResponse
    {
        $raw = json_encode($body);
        $timestamp = (string) time();

        return $this->call('POST', "/api/v1/hooks/$key", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_YACS_TIMESTAMP' => $timestamp, 'HTTP_X_YACS_EVENT_ID' => $body['event_id'], 'HTTP_X_YACS_KEY_ID' => 'k1', 'HTTP_X_YACS_SIGNATURE' => 'v1='.hash_hmac('sha256', $timestamp.'.'.$raw, $valid ? $secret : 'wrong')], $raw);
    }

    public function test_signed_channel_deduplicates_and_delivers_staff_reply_with_monotonic_receipts(): void
    {
        config(['yacs.egress.allow_insecure_for_testing' => true]);
        Http::preventStrayRequests();
        $world = World::create();
        $inbox = $world->inbox($world->brand());
        $staff = StaffClient::loginAs($this, $world->ownerStaff());
        $base = '/api/v1/workspaces/'.$world->workspace->id;
        $secret = str_repeat('x', 32);
        $created = $staff->post("$base/channel-connectors", ['brand_id' => $inbox->brand_id, 'inbox_id' => $inbox->id, 'type' => 'generic_webhook', 'name' => '測試渠道', 'key_id' => 'k1', 'secret' => $secret, 'outbound_url' => 'https://outbound.example.test/messages', 'status' => 'active']);
        $created->assertCreated();
        $this->assertMatchesOpenApi($created, 'createChannelConnector');
        $key = $created->json('data.public_key');
        $connector = $created->json('data.id');
        $payload = ['event_id' => 'event-1', 'type' => 'message.created', 'occurred_at' => now()->toISOString(), 'external_thread_id' => 'thread-1', 'external_contact' => ['subject' => 'member-1', 'name' => '會員'], 'message' => ['external_message_id' => 'remote-1', 'body_text' => '我要退款']];
        $this->hook($key, $payload, $secret, false)->assertUnauthorized();
        $first = $this->hook($key, $payload, $secret);
        $first->assertAccepted();
        $this->assertMatchesOpenApi($first, 'receiveGenericWebhook');
        $this->hook($key, $payload, $secret)->assertOk()->assertJsonPath('data.duplicate', true);
        $changed = $payload;
        $changed['message']['body_text'] = '不同內容';
        $this->hook($key, $changed, $secret)->assertConflict();
        $id = $world->in(fn () => DB::table('channel_threads')->where('connector_id', $connector)->value('conversation_id'));
        $current = $staff->get("$base/conversations/$id")->json('data');
        $claim = $staff->post("$base/conversations/$id/claim", ['expected_version' => $current['version']]);
        $claim->assertOk();
        $staff->post("$base/conversations/$id/messages", ['client_message_id' => R::id(), 'visibility' => 'internal', 'body_text' => '內部秘密', 'expected_version' => $claim->json('data.version')])->assertCreated();
        $reply = $staff->post("$base/conversations/$id/messages", ['client_message_id' => R::id(), 'visibility' => 'public', 'body_text' => '退款請至會員中心', 'expected_version' => $claim->json('data.version')]);
        $reply->assertCreated();
        $delivery = $world->in(fn () => DB::table('channel_deliveries')->first());
        $world->in(fn () => $this->assertSame(1, DB::table('channel_deliveries')->count()));
        Http::fake(['*' => Http::sequence()->push([], 503)->push(['external_message_id' => 'ack-1'], 200)]);
        $world->in(fn () => app(ChannelDeliveries::class)->deliver($delivery->id));
        $world->in(fn () => DB::table('channel_deliveries')->where('id', $delivery->id)->update(['next_attempt_at' => now()->subSecond()]));
        $world->in(fn () => app(ChannelDeliveries::class)->deliver($delivery->id));
        Http::assertSentCount(2);
        Http::assertSent(function ($request) use ($secret, $delivery): bool {
            return $request->header('X-Yacs-Event-Id')[0] === $delivery->id && hash_equals('v1='.hash_hmac('sha256', $request->header('X-Yacs-Timestamp')[0].'.'.$request->body(), $secret), $request->header('X-Yacs-Signature')[0]) && ! str_contains($request->body(), '內部秘密');
        });
        $receipt = ['event_id' => 'receipt-read', 'type' => 'message.delivery_updated', 'occurred_at' => now()->toISOString(), 'external_thread_id' => 'thread-1', 'message' => ['external_message_id' => 'ack-1', 'delivery_state' => 'read']];
        $this->hook($key, $receipt, $secret)->assertAccepted();
        $receipt['event_id'] = 'receipt-sent';
        $receipt['message']['delivery_state'] = 'sent';
        $this->hook($key, $receipt, $secret)->assertAccepted();
        $world->in(fn () => $this->assertSame('read', DB::table('channel_deliveries')->where('id', $delivery->id)->value('state')));
        $snapshot = $staff->get("$base/conversations/$id/snapshot");
        $snapshot->assertOk();
        $this->assertMatchesOpenApi($snapshot, 'snapshotStaffConversation');
    }

    public function test_webhook_auth_failure_pauses_endpoint_and_never_fans_out_internal_notes(): void
    {
        config(['yacs.egress.allow_insecure_for_testing' => true]);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 401)]);
        $world = World::create();
        $world->in(function () use ($world): void {
            $endpoint = R::id();
            $event = R::id();
            DB::table('webhook_endpoints')->insert(['id' => $endpoint, 'workspace_id' => $world->workspace->id, 'name' => 'Test', 'url' => 'https://test.example.test/hook', 'secret_encrypted' => app(SecretBox::class)->encrypt(str_repeat('x', 32), 'webhook:'.$endpoint), 'key_id' => 'k1', 'event_types' => R::encode(['conversation.updated']), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('outbox_events')->insert(['id' => $event, 'workspace_id' => $world->workspace->id, 'event_type' => 'conversation.updated', 'aggregate_type' => 'conversation', 'aggregate_id' => R::id(), 'payload_safe' => '{}', 'occurred_at' => now()]);
            app(Webhooks::class)->tick();
            app(Webhooks::class)->tick();
            $this->assertSame('paused', DB::table('webhook_endpoints')->where('id', $endpoint)->value('status'));
            $this->assertSame(1, DB::table('webhook_deliveries')->count());
            $this->assertSame(1, DB::table('webhook_attempts')->count());
        });
        Http::assertSentCount(1);
    }
}
