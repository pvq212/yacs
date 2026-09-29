<?php

declare(strict_types=1);

namespace App\Modules\Conversations;

use App\Modules\AccessControl\Authorizer;
use App\Modules\AccessControl\Permission;
use App\Modules\AccessControl\ScopeResolver;
use App\Modules\AccessControl\StaffActor;
use App\Modules\Ai\Autopilot;
use App\Modules\Identity\Visitor\VisitorActor;
use App\Support\Database\Records as R;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use App\Support\Http\Principal;
use Illuminate\Support\Facades\DB;

final class Conversations
{
    public function __construct(private readonly ConversationPolicy $policy, private readonly ConversationEvents $events) {}

    public function create(Principal $actor, array $input, string $channel = 'widget'): object
    {
        return DB::transaction(function () use ($actor, $input, $channel): object {
            $inboxId = $actor instanceof VisitorActor ? $actor->inboxId() : $input['inbox_id'];
            $contactId = $actor instanceof VisitorActor ? $actor->contactId() : $input['contact_id'];
            $inbox = DB::table('inboxes')->where('id', $inboxId)->where('status', 'active')->first();
            if ($inbox === null || ! DB::table('contacts')->where('id', $contactId)->where('brand_id', $inbox->brand_id)->where('status', 'active')->exists()) {
                throw new ApiException(ErrorCode::NotFound);
            }
            if ($actor instanceof StaffActor) {
                app(Authorizer::class)->authorize(Permission::ConversationReply, app(ScopeResolver::class)->forInbox($inboxId));
            }
            if (isset($input['related_conversation_id'])) {
                $related = $this->policy->get($actor, $input['related_conversation_id']);
                if ($related->contact_id !== $contactId || $related->inbox_id !== $inboxId) {
                    throw new ApiException(ErrorCode::NotFound);
                }
            }
            $id = R::id();
            DB::table('conversations')->insert(['id' => $id, 'workspace_id' => $actor->workspaceId(), 'brand_id' => $inbox->brand_id, 'inbox_id' => $inboxId, 'contact_id' => $contactId, 'handling_mode' => $inbox->ai_mode === 'auto_reply' ? 'ai' : 'human_queue', 'channel_source' => $channel, 'related_conversation_id' => $input['related_conversation_id'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            $c = DB::table('conversations')->where('id', $id)->first();
            $this->events->emit($c, 'conversation.updated');
            if ($c->handling_mode === 'human_queue' && (R::json($inbox->settings)['auto_assign'] ?? true)) {
                app(Assignments::class)->autoAssign($c);
            }

            return DB::table('conversations')->where('id', $id)->first();
        });
    }

    public function save(object $c, array $changes, string $event = 'conversation.updated'): object
    {
        $changes['version'] = (int) $c->version + 1;
        $changes['updated_at'] = now();
        DB::table('conversations')->where('id', $c->id)->update($changes);
        $fresh = DB::table('conversations')->where('id', $c->id)->first();
        $this->events->emit($fresh, $event);

        return $fresh;
    }

    public function handoff(object $c): object
    {
        if ($c->status === 'resolved') {
            throw new ApiException(ErrorCode::InvalidState);
        }
        if ($c->handling_mode !== 'ai') {
            return $c;
        }
        app(Autopilot::class)->cancel($c->id);
        $c = $this->save($c, ['handling_mode' => 'human_queue', 'answer_epoch' => (int) $c->answer_epoch + 1], 'conversation.handoff_requested');
        app(Assignments::class)->autoAssign($c);

        return DB::table('conversations')->where('id', $c->id)->first();
    }

    public function resolve(object $c, string $code): object
    {
        if ($c->status === 'resolved') {
            throw new ApiException(ErrorCode::InvalidState);
        }
        if (! DB::table('resolution_reasons')->where('code', $code)->where('status', 'active')->exists()) {
            throw new ApiException(ErrorCode::ValidationFailed);
        }
        app(Autopilot::class)->cancel($c->id);

        return $this->save($c, ['status' => 'resolved', 'resolved_at' => now(), 'resolution_code' => $code, 'wake_at' => null, 'answer_epoch' => (int) $c->answer_epoch + 1], 'conversation.resolved');
    }

    public function reopen(object $c): object
    {
        if ($c->status !== 'resolved') {
            throw new ApiException(ErrorCode::InvalidState);
        }

        return $this->save($c, ['status' => 'open', 'handling_mode' => 'human_queue', 'assignee_id' => null, 'team_id' => null, 'resolved_at' => null, 'resolution_code' => null, 'wake_at' => null, 'resolution_cycle' => (int) $c->resolution_cycle + 1, 'answer_epoch' => (int) $c->answer_epoch + 1, 'first_human_reply_at' => null, 'first_ai_reply_at' => null], 'conversation.reopened');
    }
}
