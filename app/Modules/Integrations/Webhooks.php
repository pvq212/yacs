<?php

declare(strict_types=1);

namespace App\Modules\Integrations;

use App\Support\Database\Records as R;
use App\Support\Security\Egress;
use App\Support\Security\SecretBox;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\DB;

/** 先 outbox commit，再建立唯一 delivery；外部投遞採至少一次與 event_id 去重。 */
final class Webhooks
{
    public function tick(int $limit = 50): void
    {
        $tenant = app(TenantDatabase::class);
        $events = $tenant->asSystem(fn () => DB::table('outbox_events')->where('state', 'pending')->orderBy('occurred_at')->limit($limit)->get());
        foreach ($events as $event) {
            $tenant->withinWorkspace($event->workspace_id, function () use ($event): void {
                DB::transaction(function () use ($event): void {
                    $event = DB::table('outbox_events')->where('id', $event->id)->lockForUpdate()->first();
                    if ($event->state !== 'pending') {
                        return;
                    }
                    $payload = R::json($event->payload_safe);
                    foreach (DB::table('webhook_endpoints')->where('status', 'active')->get() as $endpoint) {
                        if (! in_array($event->event_type, R::json($endpoint->event_types), true)) {
                            continue;
                        }
                        if ((R::json($endpoint->brand_scope) !== [] && ! in_array($payload['brand_id'] ?? '', R::json($endpoint->brand_scope), true)) || (R::json($endpoint->inbox_scope) !== [] && ! in_array($payload['inbox_id'] ?? '', R::json($endpoint->inbox_scope), true))) {
                            continue;
                        }
                        DB::table('webhook_deliveries')->insertOrIgnore(['id' => R::id(), 'workspace_id' => $event->workspace_id, 'endpoint_id' => $endpoint->id, 'event_id' => $event->id, 'event_type' => $event->event_type, 'payload' => R::encode(['event_id' => $event->id, 'type' => $event->event_type, 'occurred_at' => R::date($event->occurred_at), 'data' => $payload]), 'created_at' => now(), 'updated_at' => now()]);
                    }
                    DB::table('outbox_events')->where('id', $event->id)->update(['state' => 'dispatched', 'dispatched_at' => now()]);
                });
            });
        }
        $deliveries = $tenant->asSystem(fn () => DB::table('webhook_deliveries')->where(fn ($q) => $q->where('state', 'pending')->orWhere(fn ($q) => $q->where('state', 'delivering')->where('lease_expires_at', '<', now())))->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->limit(10)->get());
        foreach ($deliveries as $delivery) {
            $tenant->withinWorkspace($delivery->workspace_id, fn () => $this->deliver($delivery->id));
        }
    }

    public function deliver(string $id): void
    {
        $delivery = DB::transaction(function () use ($id): ?object {
            $d = DB::table('webhook_deliveries')->where('id', $id)->lockForUpdate()->first();
            if ($d === null || in_array($d->state, ['succeeded', 'failed', 'paused'], true) || ($d->state === 'delivering' && strtotime($d->lease_expires_at) > time())) {
                return null;
            }
            DB::table('webhook_deliveries')->where('id', $id)->update(['state' => 'delivering', 'lease_token' => R::id(), 'lease_expires_at' => now()->addSeconds(30), 'attempt_count' => (int) $d->attempt_count + 1]);

            return DB::table('webhook_deliveries')->where('id', $id)->first();
        });
        if ($delivery === null) {
            return;
        }
        $endpoint = DB::table('webhook_endpoints')->where('id', $delivery->endpoint_id)->where('status', 'active')->first();
        if ($endpoint === null) {
            DB::table('webhook_deliveries')->where('id', $id)->update(['state' => 'paused', 'lease_token' => null]);

            return;
        }
        $body = R::encode(R::json($delivery->payload));
        $timestamp = (string) time();
        $secret = app(SecretBox::class)->decrypt($endpoint->secret_encrypted, 'webhook:'.$endpoint->id);
        $status = null;
        $error = null;
        $retry = null;
        $started = microtime(true);
        try {
            $response = app(Egress::class)->request($endpoint->url, 10)->withHeaders(['X-Yacs-Event-Id' => $delivery->event_id, 'X-Yacs-Timestamp' => $timestamp, 'X-Yacs-Key-Id' => $endpoint->key_id, 'X-Yacs-Signature' => 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret)])->withBody($body, 'application/json')->post($endpoint->url);
            $status = $response->status();
            $retry = $response->header('Retry-After');
        } catch (\Throwable) {
            $error = 'delivery_timeout';
        }
        $state = $status !== null && $status >= 200 && $status < 300 ? 'succeeded' : (in_array($status, [401, 403], true) ? 'paused' : ($delivery->attempt_count >= 10 ? 'failed' : 'pending'));
        $delays = [30, 120, 600, 3600, 21600, 86400];
        $delay = $delays[min((int) $delivery->attempt_count - 1, count($delays) - 1)] + random_int(0, 5);
        if ($status === 429 && ctype_digit((string) $retry)) {
            $delay = max($delay, min(86400, (int) $retry));
        }
        DB::transaction(function () use ($id, $delivery, $status, $error, $state, $delay, $started): void {
            if (! DB::table('webhook_deliveries')->where('id', $id)->where('lease_token', $delivery->lease_token)->update(['state' => $state, 'last_status' => $status, 'last_error' => $error, 'next_attempt_at' => $state === 'pending' ? now()->addSeconds($delay) : null, 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now()])) {
                return;
            }
            DB::table('webhook_attempts')->insertOrIgnore(['id' => R::id(), 'workspace_id' => $delivery->workspace_id, 'delivery_id' => $id, 'attempt_no' => $delivery->attempt_count, 'signed_at' => now(), 'http_status' => $status, 'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'error_code' => $error, 'created_at' => now()]);
            if ($state === 'paused') {
                DB::table('webhook_endpoints')->where('id', $delivery->endpoint_id)->update(['status' => 'paused', 'paused_reason' => 'authentication_failed']);
            }
        });
    }
}
