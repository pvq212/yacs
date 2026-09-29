<?php

declare(strict_types=1);

namespace App\Modules\Conversations;

use App\Modules\AccessControl\Authorizer;
use App\Modules\AccessControl\Permission;
use App\Modules\AccessControl\ScopeResolver;
use App\Modules\AccessControl\StaffActor;
use App\Modules\Identity\Models\StaffSessionSecurity;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Visitor\VisitorSessions;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Models\WorkspaceMembership;
use App\Support\Database\Records as R;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\DB;

final class RealtimePublisher
{
    public function tick(): void
    {
        $tenant = app(TenantDatabase::class);
        $events = $tenant->asSystem(fn () => DB::table('realtime_events')->whereNull('published_at')->orderBy('created_at')->limit(100)->get());
        foreach ($events as $event) {
            $tenant->withinWorkspace($event->workspace_id, function () use ($event): void {
                $c = DB::table('conversations')->where('id', $event->conversation_id)->first();
                $channels = [];
                if ($event->audience === 'public') {
                    foreach (DB::table('visitor_sessions')->where('inbox_id', $c->inbox_id)->where('contact_id', $c->contact_id)->whereNull('revoked_at')->where('refresh_expires_at', '>', now())->get() as $s) {
                        try {
                            app(VisitorSessions::class)->assertActive($s);
                            $channels[] = 'visitor.session.'.$s->id.'.conversation.'.$c->id;
                        } catch (\Throwable) { /* 已撤權的 session 不再收到內容。 */
                        }
                    }
                } else {
                    foreach (WorkspaceMembership::query()->where('status', 'active')->get() as $membership) {
                        $user = User::query()->find($membership->user_id);
                        if ($user === null || ! $user->isActive()) {
                            continue;
                        }
                        $actor = new StaffActor($user, Workspace::query()->findOrFail($c->workspace_id), $membership, null);
                        $auth = new Authorizer($actor);
                        $scope = (new ScopeResolver($actor))->forConversation($c->inbox_id, $c->team_id);
                        if (! $auth->can(Permission::ConversationRead, $scope) || ($c->assignee_id !== null && $c->assignee_id !== $membership->id && $c->status !== 'resolved' && ! $auth->can(Permission::ConversationAssistOther, $scope))) {
                            continue;
                        }
                        foreach (StaffSessionSecurity::query()->where('user_id', $user->id)->whereNull('revoked_at')->where('expires_at', '>', now())->get() as $s) {
                            $channels[] = 'staff.session.'.$s->id.'.conversation.'.$c->id;
                        }
                    }
                }
                try {
                    if ($channels !== []) {
                        event(new RealtimeEvent($channels, R::json($event->payload)));
                    } DB::table('realtime_events')->where('id', $event->id)->update(['published_at' => now()]);
                } catch (\Throwable) { /* Reverb 失敗保留事件，HTTP snapshot/polling 持續可用。 */
                }
            });
        }
    }
}
