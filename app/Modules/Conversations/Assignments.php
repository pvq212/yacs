<?php

declare(strict_types=1);

namespace App\Modules\Conversations;

use App\Modules\AccessControl\Authorizer;
use App\Modules\AccessControl\Permission;
use App\Modules\AccessControl\ScopeResolver;
use App\Modules\AccessControl\StaffActor;
use App\Modules\Ai\Autopilot;
use App\Modules\Identity\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Models\WorkspaceMembership;
use App\Modules\Workspaces\Support\BusinessHours;
use App\Support\Database\Records as R;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/** 鎖順序固定為 conversation → 依 membership ID 排序的 capacity；容量在同一交易重算。 */
final class Assignments
{
    public function eligible(object $c, string $membershipId, bool $automatic = false): ?object
    {
        $membership = WorkspaceMembership::query()->whereKey($membershipId)->where('status', 'active')->first();
        if ($membership === null) {
            return null;
        }
        $user = User::query()->whereKey($membership->user_id)->first();
        if ($user === null || ! $user->isActive()) {
            return null;
        }
        $actor = new StaffActor($user, Workspace::query()->findOrFail($c->workspace_id), $membership, null);
        $scope = (new ScopeResolver($actor))->forInbox($c->inbox_id);
        $auth = new Authorizer($actor);
        if (! $auth->can(Permission::ConversationClaim, $scope) || ! $auth->can(Permission::ConversationReply, $scope)) {
            return null;
        }
        $capacity = DB::table('agent_capacity')->where('membership_id', $membershipId)->lockForUpdate()->first();
        if ($capacity === null || ($automatic && ($capacity->presence_status !== 'available' || $capacity->last_heartbeat_at === null || strtotime($capacity->last_heartbeat_at) < time() - 90))) {
            return null;
        }

        return $capacity;
    }

    public function assign(object $c, string $membershipId, ?string $actorId, bool $force = false, string $reason = '', ?string $teamId = null): object
    {
        if ($c->status === 'resolved') {
            throw new ApiException(ErrorCode::InvalidState);
        }
        $capacity = $this->eligible($c, $membershipId);
        if ($capacity === null) {
            throw new ApiException(ErrorCode::Forbidden);
        }
        $active = DB::table('conversations')->where('assignee_id', $membershipId)->whereIn('status', ['open', 'waiting_customer'])->where('id', '<>', $c->id)->count();
        if ($active >= $capacity->max_active && ! $force) {
            throw new ApiException(ErrorCode::CapacityExceeded);
        }
        if ($force && trim($reason) === '') {
            throw new ApiException(ErrorCode::ValidationFailed);
        }
        if ($teamId !== null && ! DB::table('teams')->where('id', $teamId)->where('status', 'active')->exists()) {
            throw new ApiException(ErrorCode::NotFound);
        }
        DB::table('conversation_assignments')->insert(['id' => R::id(), 'workspace_id' => $c->workspace_id, 'conversation_id' => $c->id, 'from_assignee_id' => $c->assignee_id, 'to_assignee_id' => $membershipId, 'reason' => $reason ?: 'claim', 'actor_type' => $actorId === null ? 'system' : 'staff', 'actor_id' => $actorId, 'forced' => $force, 'created_at' => now()]);
        DB::table('agent_capacity')->where('id', $capacity->id)->update(['last_assigned_at' => now(), 'updated_at' => now()]);
        app(Autopilot::class)->cancel($c->id);

        return app(Conversations::class)->save($c, ['status' => 'open', 'handling_mode' => 'human', 'assignee_id' => $membershipId, 'team_id' => $teamId, 'wake_at' => null, 'answer_epoch' => (int) $c->answer_epoch + 1], 'conversation.assigned');
    }

    public function autoAssign(object $c): void
    {
        if ($c->handling_mode !== 'human_queue') {
            return;
        }
        $inbox = DB::table('inboxes')->where('id', $c->inbox_id)->first();
        if (! BusinessHours::isOpen($inbox)) {
            return;
        }
        if (! (R::json($inbox->settings)['auto_assign'] ?? true)) {
            return;
        }
        $candidates = DB::table('agent_capacity')->where('presence_status', 'available')->where('last_heartbeat_at', '>', now()->subSeconds(90))->orderBy('membership_id')->get();
        // 先按固定次序鎖所有候選，避免多人轉派造成死結。
        $eligible = [];
        foreach ($candidates as $candidate) {
            $capacity = $this->eligible($c, $candidate->membership_id, true);
            if ($capacity !== null) {
                $count = DB::table('conversations')->where('assignee_id', $candidate->membership_id)->whereIn('status', ['open', 'waiting_customer'])->count();
                if ($count < $capacity->max_active) {
                    $eligible[] = [$count, $capacity->last_assigned_at ?? '', $candidate->membership_id];
                }
            }
        }
        sort($eligible);
        if ($eligible !== []) {
            $this->assign($c, $eligible[0][2], null);
        }
    }
}
