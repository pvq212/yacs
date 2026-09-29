<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Controllers;

use App\Modules\Conversations\ConversationEvents;
use App\Modules\Conversations\Conversations;
use App\Modules\Conversations\Messages;
use App\Modules\Integrations\Contacts;
use App\Modules\Integrations\IntegrationActor;
use App\Support\Database\Records as R;
use App\Support\Http\ApiException;
use App\Support\Http\ApiResponse;
use App\Support\Http\ContractRequest;
use App\Support\Http\ErrorCode;
use App\Support\Security\SecretBox;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\DB;

final class HookController
{
    public function __invoke(ContractRequest $request): mixed
    {
        $tenant = app(TenantDatabase::class);
        $key = $request->route('connector_key');
        $connector = $tenant->asSystem(fn () => DB::table('channel_connectors')->where('public_key', $key)->where('status', 'active')->first());
        if ($connector === null) {
            throw new ApiException(ErrorCode::NotFound);
        }
        $tenant->enterWorkspace($connector->workspace_id);
        $input = $request->payload();
        $timestamp = (string) $request->header('X-Yacs-Timestamp');
        $secret = app(SecretBox::class)->decrypt($connector->secret_encrypted, 'connector:'.$connector->id);
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300 || $request->header('X-Yacs-Event-Id') !== $input['event_id'] || $request->header('X-Yacs-Key-Id') !== $connector->key_id || ! hash_equals('v1='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret), (string) $request->header('X-Yacs-Signature'))) {
            throw new ApiException(ErrorCode::Unauthenticated);
        }
        if (($input['type'] === 'message.created' && ! isset($input['external_contact']['subject'])) || ($input['message']['attachment_urls'] ?? []) !== []) {
            throw new ApiException(ErrorCode::CapabilityUnsupported);
        }

        return DB::transaction(function () use ($connector, $input, $request): mixed {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$connector->id.'|'.$input['external_thread_id']]);
            $hash = hash('sha256', $request->getContent());
            $existing = DB::table('inbound_events')->where('connector_id', $connector->id)->where('external_event_id', $input['event_id'])->first();
            if ($existing !== null) {
                if (! hash_equals($existing->body_hash, $hash)) {
                    throw new ApiException(ErrorCode::IdempotencyConflict);
                }

                return ApiResponse::data(['event_id' => $input['event_id'], 'accepted' => true, 'duplicate' => true]);
            }
            if ($input['type'] === 'message.delivery_updated') {
                $candidate = DB::table('channel_deliveries as d')->join('messages as m', 'm.id', '=', 'd.message_id')->join('channel_threads as t', 't.conversation_id', '=', 'm.conversation_id')->where('d.connector_id', $connector->id)->where('t.connector_id', $connector->id)->where('t.external_thread_id', $input['external_thread_id'])->where(function ($q) use ($input): void {
                    if (isset($input['message']['delivery_id'])) {
                        $q->where('d.id', $input['message']['delivery_id']);
                    } else {
                        $q->where('d.external_message_id', $input['message']['external_message_id']);
                    }
                })->select('d.*', 'm.conversation_id')->first();
                if ($candidate === null || ! isset($input['message']['delivery_state'])) {
                    throw new ApiException(ErrorCode::NotFound);
                }
                $c = DB::table('conversations')->where('id', $candidate->conversation_id)->lockForUpdate()->first();
                $delivery = DB::table('channel_deliveries')->where('id', $candidate->id)->lockForUpdate()->first();
                $state = $input['message']['delivery_state'];
                $rank = ['pending' => 0, 'sending' => 1, 'unknown' => 1, 'failed' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4];
                if ($rank[$state] >= $rank[$delivery->state]) {
                    DB::table('channel_deliveries')->where('id', $delivery->id)->update(['state' => $state, 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
                    app(ConversationEvents::class)->emit($c, 'message.delivery_updated', staffOnly: ['message_id' => $delivery->message_id, 'state' => $state]);
                }
                DB::table('inbound_events')->insert(['id' => R::id(), 'workspace_id' => $connector->workspace_id, 'connector_id' => $connector->id, 'external_event_id' => $input['event_id'], 'body_hash' => $hash, 'state' => 'processed', 'occurred_at' => $input['occurred_at'], 'received_at' => now(), 'processed_at' => now()]);

                return ApiResponse::data(['event_id' => $input['event_id'], 'accepted' => true, 'duplicate' => false], 202);
            }
            $issuer = 'connector:'.$connector->id;
            $contact = app(Contacts::class)->upsert($connector->workspace_id, $connector->brand_id, $issuer, $input['external_contact']['subject'], ['name' => $input['external_contact']['name'] ?? '外部客戶']);
            $client = (object) ['id' => $connector->id, 'workspace_id' => $connector->workspace_id, 'brand_scope' => R::encode([$connector->brand_id]), 'inbox_scope' => R::encode([$connector->inbox_id]), 'contact_issuer' => $issuer, 'scopes' => R::encode(['conversations.create', 'messages.write'])];
            $actor = new IntegrationActor($client);
            $thread = DB::table('channel_threads as t')->join('conversations as c', 'c.id', '=', 't.conversation_id')->where('t.connector_id', $connector->id)->where('t.external_thread_id', $input['external_thread_id'])->where('t.contact_id', $contact->id)->orderByDesc('t.created_at')->select('c.*')->first();
            if ($thread === null || ($thread->status === 'resolved' && strtotime($thread->resolved_at) < time() - 72 * 3600)) {
                $thread = app(Conversations::class)->create($actor, ['inbox_id' => $connector->inbox_id, 'contact_id' => $contact->id], 'webhook');
                DB::table('channel_threads')->insert(['id' => R::id(), 'workspace_id' => $connector->workspace_id, 'connector_id' => $connector->id, 'external_thread_id' => $input['external_thread_id'], 'contact_id' => $contact->id, 'conversation_id' => $thread->id, 'created_at' => now()]);
            }
            $hashId = hash('sha256', $connector->id.'|'.$input['message']['external_message_id']);
            $uuid = substr($hashId, 0, 8).'-'.substr($hashId, 8, 4).'-4'.substr($hashId, 13, 3).'-a'.substr($hashId, 17, 3).'-'.substr($hashId, 20, 12);
            app(Messages::class)->send($actor, $thread->id, ['client_message_id' => $uuid, 'body_text' => $input['message']['body_text'] ?? '']);
            DB::table('inbound_events')->insert(['id' => R::id(), 'workspace_id' => $connector->workspace_id, 'connector_id' => $connector->id, 'external_event_id' => $input['event_id'], 'body_hash' => $hash, 'state' => 'processed', 'occurred_at' => $input['occurred_at'], 'received_at' => now(), 'processed_at' => now()]);

            return ApiResponse::data(['event_id' => $input['event_id'], 'accepted' => true, 'duplicate' => false], 202);
        });
    }
}
