<?php

declare(strict_types=1);

namespace App\Modules\Conversations;

use App\Support\Database\Records as R;
use Illuminate\Support\Facades\DB;

/** 呼叫端必須已鎖 conversation；每種受眾有獨立游標與資料投影。 */
final class ConversationEvents
{
    public function emit(object $c, string $type, ?object $message = null, ?array $staffOnly = null): void
    {
        $internal = $staffOnly !== null || ($message !== null && $message->visibility === 'internal');
        foreach ($internal ? ['staff'] : ['public', 'staff'] as $audience) {
            $column = $audience.'_event_seq';
            $c->$column = (int) $c->$column + 1;
            $data = $staffOnly ?? ($message ? ['message' => ConversationResource::message($message, $audience === 'staff')] : ['conversation' => ConversationResource::conversation($c, $audience === 'staff')]);
            $id = R::id();
            $payload = ['schema_version' => '1.0', 'event_id' => $id, 'type' => $type, 'workspace_id' => $c->workspace_id, 'conversation_id' => $c->id, 'event_seq' => (string) $c->$column, 'occurred_at' => now()->toISOString(), 'data' => $data];
            DB::table('realtime_events')->insert(['id' => $id, 'workspace_id' => $c->workspace_id, 'conversation_id' => $c->id, 'audience' => $audience, 'event_seq' => $c->$column, 'type' => $type, 'payload' => R::encode($payload), 'created_at' => now()]);
        }
        DB::table('conversations')->where('id', $c->id)->update(['public_event_seq' => $c->public_event_seq, 'staff_event_seq' => $c->staff_event_seq]);
        if (! $internal) {
            DB::table('outbox_events')->insert(['id' => R::id(), 'workspace_id' => $c->workspace_id, 'event_type' => $type, 'aggregate_type' => 'conversation', 'aggregate_id' => $c->id, 'conversation_id' => $c->id, 'payload_safe' => R::encode(['conversation' => ConversationResource::conversation($c), 'brand_id' => $c->brand_id, 'inbox_id' => $c->inbox_id] + ($message ? ['message' => ConversationResource::message($message)] : [])), 'occurred_at' => now()]);
        }
    }
}
