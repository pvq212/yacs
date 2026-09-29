<?php

declare(strict_types=1);

namespace App\Modules\Integrations;

use App\Modules\Conversations\ConversationEvents;
use App\Support\Database\Records as R;
use App\Support\Security\Egress;
use App\Support\Security\SecretBox;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\DB;

/** 通用文字渠道：message 與 delivery 同交易；接收端必須以固定 event_id 去重。 */
final class ChannelDeliveries
{
    public function enqueue(object $message): void
    {
        if ($message->visibility !== 'public' || ! in_array($message->author_type, ['staff', 'ai'], true)) {
            return;
        }
        foreach (DB::table('channel_threads')->where('conversation_id', $message->conversation_id)->get() as $thread) {
            DB::table('channel_deliveries')->insertOrIgnore(['id' => R::id(), 'workspace_id' => $message->workspace_id, 'message_id' => $message->id, 'connector_id' => $thread->connector_id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function tick(): void
    {
        $tenant = app(TenantDatabase::class);
        $rows = $tenant->asSystem(fn () => DB::table('channel_deliveries')->where(fn ($q) => $q->where('state', 'pending')->orWhere(fn ($q) => $q->where('state', 'sending')->where('lease_expires_at', '<', now())))->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('id')->limit(10)->get());
        foreach ($rows as $row) {
            $tenant->withinWorkspace($row->workspace_id, fn () => $this->deliver($row->id));
        }
    }

    public function deliver(string $id): void
    {
        $delivery = DB::transaction(function () use ($id): ?object {
            $row = DB::table('channel_deliveries')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || ! in_array($row->state, ['pending', 'sending'], true) || ($row->state === 'sending' && strtotime($row->lease_expires_at) > time()) || ($row->next_attempt_at && strtotime($row->next_attempt_at) > time())) {
                return null;
            }
            if ($row->attempt_count >= 10) {
                DB::table('channel_deliveries')->where('id', $id)->update(['state' => 'failed', 'last_error_code' => 'attempts_exhausted']);

                return null;
            }
            DB::table('channel_deliveries')->where('id', $id)->update(['state' => 'sending', 'attempt_count' => (int) $row->attempt_count + 1, 'lease_token' => R::id(), 'lease_expires_at' => now()->addSeconds(30), 'last_attempt_at' => now(), 'updated_at' => now()]);

            return DB::table('channel_deliveries')->where('id', $id)->first();
        });
        if ($delivery === null) {
            return;
        }
        $connector = DB::table('channel_connectors')->where('id', $delivery->connector_id)->where('status', 'active')->first();
        $message = DB::table('messages')->where('id', $delivery->message_id)->first();
        $thread = $message ? DB::table('channel_threads')->where('connector_id', $delivery->connector_id)->where('conversation_id', $message->conversation_id)->first() : null;
        $status = null;
        $externalId = null;
        $error = null;
        $retryAfter = null;
        $started = microtime(true);
        try {
            if ($connector === null || $connector->outbound_url === null || $message === null || $thread === null) {
                throw new \RuntimeException('connector_unavailable');
            }
            $body = R::encode(['event_id' => $delivery->id, 'type' => 'message.created', 'occurred_at' => R::date($message->created_at), 'external_thread_id' => $thread->external_thread_id, 'message' => ['id' => $message->id, 'body_text' => $message->body_text, 'author_type' => $message->author_type]]);
            $timestamp = (string) time();
            $secret = app(SecretBox::class)->decrypt($connector->secret_encrypted, 'connector:'.$connector->id);
            $response = app(Egress::class)->request($connector->outbound_url, 10)->withHeaders(['X-Yacs-Event-Id' => $delivery->id, 'X-Yacs-Key-Id' => $connector->key_id, 'X-Yacs-Timestamp' => $timestamp, 'X-Yacs-Signature' => 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret)])->withBody($body, 'application/json')->post($connector->outbound_url);
            $status = $response->status();
            $retryAfter = $response->header('Retry-After');
            $externalId = $response->json('external_message_id');
            if (! is_string($externalId) || mb_strlen($externalId) > 200) {
                $externalId = null;
            }
        } catch (\Throwable) {
            $error = 'connector_unavailable';
        }
        $success = $status !== null && $status >= 200 && $status < 300;
        $terminal = in_array($status, [400, 401, 403, 404, 410, 422], true) || $delivery->attempt_count >= 10;
        $state = $success ? 'sent' : ($terminal ? 'failed' : 'pending');
        $error ??= $success ? null : 'http_delivery_failed';
        $delay = [30, 120, 600, 3600, 21600, 86400][min(5, (int) $delivery->attempt_count - 1)] + random_int(0, 5);
        if ($status === 429 && ctype_digit((string) $retryAfter)) {
            $delay = max($delay, min(86400, (int) $retryAfter));
        }
        DB::transaction(function () use ($delivery, $state, $status, $error, $externalId, $delay, $started, $message, $success): void {
            // 所有訊息變更的鎖順序為 conversation → delivery。
            $c = $message ? DB::table('conversations')->where('id', $message->conversation_id)->lockForUpdate()->first() : null;
            if (! DB::table('channel_deliveries')->where('id', $delivery->id)->where('state', 'sending')->where('lease_token', $delivery->lease_token)->where('lease_expires_at', '>', now())->update(['state' => $state, 'external_message_id' => $externalId, 'last_error_code' => $error, 'next_attempt_at' => $state === 'pending' ? now()->addSeconds($delay) : null, 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now()])) {
                return;
            }
            DB::table('delivery_attempts')->insertOrIgnore(['id' => R::id(), 'workspace_id' => $delivery->workspace_id, 'delivery_id' => $delivery->id, 'attempt_no' => $delivery->attempt_count, 'request_id_safe' => $delivery->id, 'response_code' => $status, 'result_state' => $state, 'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'created_at' => now()]);
            DB::table('channel_connectors')->where('id', $delivery->connector_id)->update(['health' => $success ? 'healthy' : 'degraded', 'last_error_code' => $error, 'last_success_at' => $success ? now() : null]);
            if ($c && in_array($state, ['sent', 'failed'], true)) {
                app(ConversationEvents::class)->emit($c, 'message.delivery_updated', staffOnly: ['message_id' => $delivery->message_id, 'state' => $state]);
            }
        });
    }
}
