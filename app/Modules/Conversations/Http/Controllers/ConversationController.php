<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Http\Controllers;

use App\Modules\AccessControl\Permission;
use App\Modules\AccessControl\StaffActor;
use App\Modules\Ai\Autopilot;
use App\Modules\Conversations\Assignments;
use App\Modules\Conversations\ConversationPolicy;
use App\Modules\Conversations\ConversationResource as DTO;
use App\Modules\Conversations\Conversations;
use App\Modules\Conversations\Messages;
use App\Modules\Identity\Visitor\VisitorActor;
use App\Support\Database\Records as R;
use App\Support\Http\ApiException;
use App\Support\Http\ApiResponse;
use App\Support\Http\ContractRequest;
use App\Support\Http\ErrorCode;
use App\Support\Http\Principal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ConversationController
{
    public function __construct(private readonly ConversationPolicy $policy, private readonly Conversations $conversations) {}

    public function __invoke(ContractRequest $request): mixed
    {
        $op = $request->route()->getName();
        $input = $request->payload();
        $actor = app(Principal::class);
        if (! ($actor instanceof StaffActor || $actor instanceof VisitorActor)) {
            throw new ApiException(ErrorCode::Forbidden);
        }
        $staff = $actor instanceof StaffActor;
        $id = $request->route('conversation_id');
        if (in_array($op, ['createVisitorConversation', 'createStaffConversation', 'createIntegrationConversation'], true)) {
            return ApiResponse::data(DTO::conversation($this->conversations->create($actor, $input, $staff ? 'staff' : 'widget'), $staff), 201);
        }
        if (in_array($op, ['listVisitorConversations', 'listStaffConversations'], true)) {
            $query = $this->policy->query($actor);
            foreach (['status', 'handling_mode', 'inbox_id', 'assignee_id', 'contact_id'] as $field) {
                if (isset($input[$field])) {
                    $query->where($field, $input[$field]);
                }
            }
            if (isset($input['q'])) {
                $query->whereExists(fn ($q) => $q->selectRaw('1')->from('messages')->whereColumn('messages.conversation_id', 'conversations.id')->where('body_text', 'ilike', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $input['q']).'%'));
            }
            if (isset($input['cursor'])) {
                if (! Str::isUuid($input['cursor'])) {
                    throw new ApiException(ErrorCode::ValidationFailed);
                } $query->where('id', '<', $input['cursor']);
            }
            $limit = (int) ($input['limit'] ?? 50);
            $rows = $query->orderByDesc('id')->limit($limit + 1)->get();

            return ApiResponse::list($rows->take($limit)->map(fn ($c) => DTO::conversation($c, $staff))->all(), $rows->count() > $limit ? $rows[$limit - 1]->id : null);
        }
        $c = $this->policy->get($actor, $id);
        if (str_starts_with($op, 'get') && str_contains($op, 'Conversation')) {
            return ApiResponse::data(DTO::conversation($c, $staff));
        }
        if (str_starts_with($op, 'snapshot')) {
            return DB::transaction(function () use ($actor, $id, $staff): mixed {
                $c = $this->policy->get($actor, $id, true);
                $messages = $this->messageQuery($c, $staff)->orderByDesc('message_seq')->limit(51)->get();

                return ApiResponse::data(['conversation' => DTO::conversation($c, $staff), 'messages' => $messages->take(50)->reverse()->values()->map(fn ($m) => DTO::message($m, $staff))->all(), 'last_event_seq' => (string) ($staff ? $c->staff_event_seq : $c->public_event_seq), 'older_messages_cursor' => $messages->count() > 50 ? (string) $messages[49]->message_seq : null]);
            });
        }
        if (str_starts_with($op, 'list') && str_contains($op, 'Messages')) {
            $q = $this->messageQuery($c, $staff);
            $before = $input['before_seq'] ?? $input['cursor'] ?? null;
            if ($before !== null) {
                if (! ctype_digit($before)) {
                    throw new ApiException(ErrorCode::ValidationFailed);
                } $q->where('message_seq', '<', $before);
            }
            $limit = (int) ($input['limit'] ?? 50);
            $rows = $q->orderByDesc('message_seq')->limit($limit + 1)->get();

            return ApiResponse::list($rows->take($limit)->reverse()->values()->map(fn ($m) => DTO::message($m, $staff))->all(), $rows->count() > $limit ? (string) $rows[$limit - 1]->message_seq : null);
        }
        if (str_starts_with($op, 'list') && str_contains($op, 'Events')) {
            $q = DB::table('realtime_events')->where('conversation_id', $c->id)->where('audience', $staff ? 'staff' : 'public');
            $earliest = (clone $q)->where('created_at', '>', now()->subDays(7))->min('event_seq');
            if ($earliest !== null && (int) $input['after_seq'] < (int) $earliest - 1) {
                throw new ApiException(ErrorCode::CursorExpired);
            }
            $rows = $q->where('created_at', '>', now()->subDays(7))->where('event_seq', '>', $input['after_seq'])->orderBy('event_seq')->limit((int) ($input['limit'] ?? 50))->get();

            return ApiResponse::list($rows->map(fn ($e) => R::json($e->payload))->all());
        }
        if (str_starts_with($op, 'send') && str_contains($op, 'Message')) {
            return ApiResponse::data(DTO::message(app(Messages::class)->send($actor, $id, $input), $staff), 201);
        }
        if (str_starts_with($op, 'mark')) {
            $latest = (int) $this->messageQuery($c, $staff)->max('message_seq');
            if ((int) $input['last_read_message_seq'] > $latest) {
                throw new ApiException(ErrorCode::ValidationFailed);
            }
            $readerId = $actor instanceof StaffActor ? $actor->membershipId() : $actor->contactId();
            DB::statement('INSERT INTO conversation_reads (id, workspace_id, conversation_id, reader_type, reader_id, last_read_message_seq, read_at) VALUES (?, ?, ?, ?, ?, ?, now()) ON CONFLICT (conversation_id, reader_type, reader_id) DO UPDATE SET last_read_message_seq = GREATEST(conversation_reads.last_read_message_seq, EXCLUDED.last_read_message_seq), read_at = now()', [R::id(), $c->workspace_id, $c->id, $staff ? 'membership' : 'contact', $readerId, $input['last_read_message_seq']]);

            return ApiResponse::noContent();
        }
        if ($op === 'submitCsat') {
            if ($c->status !== 'resolved' || (int) $c->resolution_cycle !== $input['resolution_cycle']) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            DB::table('csat_responses')->insertOrIgnore(['id' => R::id(), 'workspace_id' => $c->workspace_id, 'conversation_id' => $c->id, 'contact_id' => $c->contact_id, 'resolution_cycle' => $input['resolution_cycle'], 'score' => $input['score'], 'comment' => $input['comment'] ?? null, 'submitted_at' => now()]);

            return ApiResponse::noContent();
        }

        return DB::transaction(function () use ($actor, $id, $staff, $op, $input): mixed {
            $c = $this->policy->get($actor, $id, true);
            if ($staff) {
                $this->policy->version($c, $input['expected_version']);
            }
            if ($op === 'requestHuman') {
                $c = $this->conversations->handoff($c);
            } elseif ($op === 'visitorConfirmResolution') {
                $c = $this->conversations->resolve($c, 'resolved_by_customer');
            } elseif (! $actor instanceof StaffActor) {
                throw new ApiException(ErrorCode::Forbidden);
            } elseif ($op === 'staffClaim') {
                $this->policy->write($actor, $c, Permission::ConversationClaim, false);
                if ($c->status !== 'open' || ! in_array($c->handling_mode, ['ai', 'human_queue'], true)) {
                    throw new ApiException(ErrorCode::InvalidState);
                }
                $c = app(Assignments::class)->assign($c, $actor->membershipId(), $actor->membershipId());
            } elseif ($op === 'staffAssign') {
                $this->policy->write($actor, $c, Permission::ConversationAssign, false);
                $c = app(Assignments::class)->assign($c, $input['assignee_id'], $actor->membershipId(), $input['force'] ?? false, $input['reason'] ?? '', $input['team_id'] ?? null);
            } elseif ($op === 'staffResolve') {
                $this->policy->write($actor, $c, Permission::ConversationResolve);
                $c = $this->conversations->resolve($c, $input['resolution_code']);
            } elseif ($op === 'staffReopen') {
                $this->policy->write($actor, $c, Permission::ConversationReopen, false);
                $c = $this->conversations->reopen($c);
            } elseif ($op === 'staffRelease') {
                $this->policy->write($actor, $c, Permission::ConversationClaim);
                if ($c->handling_mode !== 'human' || $c->status === 'resolved') {
                    throw new ApiException(ErrorCode::InvalidState);
                }
                $c = $this->conversations->save($c, ['status' => 'open', 'handling_mode' => 'human_queue', 'assignee_id' => null, 'wake_at' => null, 'answer_epoch' => (int) $c->answer_epoch + 1]);
            } elseif ($op === 'staffSnooze') {
                $this->policy->write($actor, $c, Permission::ConversationResolve);
                if ($c->handling_mode !== 'human' || $c->status === 'resolved' || strtotime($input['wake_at']) <= time()) {
                    throw new ApiException(ErrorCode::InvalidState);
                }
                $c = $this->conversations->save($c, ['status' => 'snoozed', 'wake_at' => $input['wake_at']]);
            } elseif ($op === 'staffResumeAi') {
                $this->policy->write($actor, $c, Permission::ConversationAssign);
                if (DB::table('inboxes')->where('id', $c->inbox_id)->value('ai_mode') !== 'auto_reply' || $c->status === 'resolved') {
                    throw new ApiException(ErrorCode::InvalidState);
                }
                $c = $this->conversations->save($c, ['status' => 'open', 'handling_mode' => 'ai', 'assignee_id' => null, 'wake_at' => null, 'answer_epoch' => (int) $c->answer_epoch + 1]);
                app(Autopilot::class)->enqueue($c);
            } else {
                throw new \LogicException('Unknown conversation action: '.$op);
            }

            return ApiResponse::data(DTO::conversation($c, $staff));
        });
    }

    private function messageQuery(object $c, bool $staff): Builder
    {
        $q = DB::table('messages')->where('conversation_id', $c->id);

        return $staff ? $q : $q->where('visibility', 'public');
    }
}
