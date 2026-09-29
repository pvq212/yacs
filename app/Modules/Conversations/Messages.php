<?php

declare(strict_types=1);

namespace App\Modules\Conversations;

use App\Modules\AccessControl\Permission;
use App\Modules\AccessControl\StaffActor;
use App\Modules\Ai\Autopilot;
use App\Modules\Identity\Visitor\VisitorActor;
use App\Modules\Integrations\ChannelDeliveries;
use App\Support\Database\Records as R;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use App\Support\Http\Principal;
use Illuminate\Support\Facades\DB;

final class Messages
{
    public function __construct(private readonly ConversationPolicy $policy, private readonly ConversationEvents $events) {}

    public function send(Principal $actor, string $conversationId, array $input): object
    {
        return DB::transaction(function () use ($actor, $conversationId, $input): object {
            $c = $this->policy->get($actor, $conversationId, true);
            $source = $actor->principalScope();
            $old = DB::table('messages')->where('source_scope', $source)->where('client_message_id', $input['client_message_id'])->first();
            $visibility = $actor instanceof StaffActor ? $input['visibility'] : 'public';
            $attachments = $input['attachment_ids'] ?? [];
            if ($old !== null) {
                $existing = DB::table('message_attachments')->where('message_id', $old->id)->orderBy('position')->pluck('file_id')->all();
                if ($old->conversation_id !== $c->id || $old->body_text !== ($input['body_text'] ?? '') || $old->visibility !== $visibility || $existing !== $attachments) {
                    throw new ApiException(ErrorCode::IdempotencyConflict);
                }

                return $old;
            }
            if ($actor instanceof StaffActor) {
                $this->policy->version($c, $input['expected_version']);
                $this->policy->write($actor, $c, $visibility === 'internal' ? Permission::ConversationNote : Permission::ConversationReply, $visibility === 'public');
                if ($visibility === 'public' && ($c->handling_mode !== 'human' || $c->status === 'resolved' || $c->status === 'snoozed')) {
                    throw new ApiException(ErrorCode::InvalidState);
                }
            } elseif ($c->status === 'resolved') {
                if (strtotime($c->resolved_at) < time() - 72 * 3600) {
                    throw new ApiException(ErrorCode::NewConversationRequired);
                }
                $c = app(Conversations::class)->reopen($c);
            }
            DB::table('files')->whereIn('id', $attachments)->orderBy('id')->lockForUpdate()->get();
            if ($actor instanceof StaffActor && $visibility === 'public' && $attachments !== [] && DB::table('channel_threads')->where('conversation_id', $c->id)->exists()) {
                throw new ApiException(ErrorCode::CapabilityUnsupported);
            }
            foreach ($attachments as $id) {
                $file = DB::table('files')->where('id', $id)->where('scan_state', 'clean')->whereNull('deleted_at')->where('purpose', 'chat')->first();
                if (DB::table('message_attachments as a')->join('messages as m', 'm.id', '=', 'a.message_id')->where('a.file_id', $id)->where('m.conversation_id', '<>', $c->id)->exists()) {
                    throw new ApiException(ErrorCode::Forbidden);
                }
                if ($file === null || ($file->brand_id !== null && $file->brand_id !== $c->brand_id) || ($actor instanceof VisitorActor && $file->contact_id !== $actor->contactId()) || ($actor instanceof StaffActor && ($file->owner_type !== 'staff' || $file->owner_id !== $actor->membershipId()))) {
                    throw new ApiException(ErrorCode::Forbidden);
                }
            }
            foreach ($attachments as $id) {
                DB::table('files')->where('id', $id)->whereNull('brand_id')->update(['brand_id' => $c->brand_id]);
            }
            $author = $actor instanceof StaffActor ? 'staff' : 'visitor';
            $message = $this->append($c, ['author_type' => $author, 'visibility' => $visibility, 'body_text' => $input['body_text'] ?? '', 'kind' => $attachments === [] ? 'text' : 'attachment', 'source_scope' => $source, 'client_message_id' => $input['client_message_id'], 'staff_membership_id' => $actor instanceof StaffActor ? $actor->membershipId() : null, 'contact_id' => $author === 'visitor' ? $c->contact_id : null], $attachments);
            $changes = [];
            if ($author === 'visitor') {
                $changes = ['latest_customer_message_id' => $message->id, 'last_customer_message_at' => now(), 'awaiting_reply_since' => $c->awaiting_reply_since ?? now(), 'answer_epoch' => (int) $c->answer_epoch + 1, 'status' => 'open', 'wake_at' => null];
                if ($c->status === 'snoozed') {
                    $changes += ['handling_mode' => 'human_queue', 'assignee_id' => null];
                }
            } elseif ($visibility === 'public') {
                $changes = ['awaiting_reply_since' => null, 'first_human_reply_at' => $c->first_human_reply_at ?? now(), 'status' => ($input['after_send'] ?? 'keep_open') === 'waiting_customer' ? 'waiting_customer' : 'open'];
            }
            if ($changes !== []) {
                $c = app(Conversations::class)->save($c, $changes);
            }
            if ($author === 'visitor') {
                app(Autopilot::class)->cancel($c->id);
                if ($c->handling_mode === 'ai') {
                    app(Autopilot::class)->enqueue($c);
                }
                if ($c->handling_mode === 'human_queue') {
                    app(Assignments::class)->autoAssign($c);
                }
            }

            return $message;
        });
    }

    /** 僅受信任的 Actions 可使用；仍需已鎖定 conversation。 */
    public function append(object $c, array $data, array $attachments = []): object
    {
        $id = R::id();
        DB::table('messages')->insert(array_merge(['id' => $id, 'workspace_id' => $c->workspace_id, 'conversation_id' => $c->id, 'message_seq' => $c->next_message_seq, 'created_at' => now(), 'metadata' => '{}'], $data));
        foreach ($attachments as $position => $fileId) {
            DB::table('message_attachments')->insert(['id' => R::id(), 'workspace_id' => $c->workspace_id, 'message_id' => $id, 'file_id' => $fileId, 'position' => $position]);
        }
        $c->next_message_seq = (int) $c->next_message_seq + 1;
        DB::table('conversations')->where('id', $c->id)->update(['next_message_seq' => $c->next_message_seq, 'last_message_at' => now(), 'updated_at' => now()]);
        $message = DB::table('messages')->where('id', $id)->first();
        app(ChannelDeliveries::class)->enqueue($message);
        $this->events->emit($c, 'message.created', $message);

        return $message;
    }
}
