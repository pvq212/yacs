<?php

declare(strict_types=1);

namespace App\Modules\Tasks;

use App\Modules\Ai\Autopilot;
use App\Modules\Conversations\Assignments;
use App\Modules\Conversations\Conversations;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\DB;

/** 獨立於 AI queue 的 DB 掃描器；無論 worker 或 Redis 是否故障都能交人工。 */
final class Watchdog
{
    public function tick(): void
    {
        $tenant = app(TenantDatabase::class);
        $runs = $tenant->asSystem(fn () => DB::table('ai_runs')->whereIn('state', ['queued', 'running'])->where('deadline_at', '<=', now())->limit(200)->get());
        foreach ($runs as $run) {
            $tenant->withinWorkspace($run->workspace_id, fn () => app(Autopilot::class)->fail($run, 'deadline_exceeded'));
        }
        $sleepers = $tenant->asSystem(fn () => DB::table('conversations')->where('status', 'snoozed')->where('wake_at', '<=', now())->limit(100)->get());
        foreach ($sleepers as $sleeping) {
            $tenant->withinWorkspace($sleeping->workspace_id, fn () => DB::transaction(function () use ($sleeping): void {
                $c = DB::table('conversations')->where('id', $sleeping->id)->lockForUpdate()->first();
                if ($c->status !== 'snoozed') {
                    return;
                }
                $c = app(Conversations::class)->save($c, ['status' => 'open', 'wake_at' => null, 'handling_mode' => 'human_queue', 'assignee_id' => null]);
                app(Assignments::class)->autoAssign($c);
            }));
        }
        $tenant->asSystem(fn () => DB::table('agent_capacity')->where('presence_status', '<>', 'offline')->where('last_heartbeat_at', '<', now()->subSeconds(90))->update(['presence_status' => 'offline', 'updated_at' => now()]));
        $pending = $tenant->asSystem(fn () => DB::table('conversations')->whereIn('status', ['open', 'waiting_customer'])->whereIn('handling_mode', ['human_queue', 'human'])->orderBy('awaiting_reply_since')->limit(200)->get());
        foreach ($pending as $row) {
            $tenant->withinWorkspace($row->workspace_id, fn () => DB::transaction(function () use ($row): void {
                $c = DB::table('conversations')->where('id', $row->id)->lockForUpdate()->first();
                if (! in_array($c->status, ['open', 'waiting_customer'], true)) {
                    return;
                }
                if ($c->handling_mode === 'human' && $c->assignee_id !== null && app(Assignments::class)->eligible($c, $c->assignee_id) === null) {
                    $c = app(Conversations::class)->save($c, ['handling_mode' => 'human_queue', 'assignee_id' => null, 'answer_epoch' => (int) $c->answer_epoch + 1]);
                }
                if ($c->handling_mode === 'human_queue') {
                    app(Assignments::class)->autoAssign($c);
                }
            }));
        }
        $tenant->asSystem(fn () => DB::table('idempotency_records')->where('expires_at', '<', now())->delete());
    }
}
