<?php

declare(strict_types=1);

namespace App\Modules\Tasks;

use App\Modules\Ai\Autopilot;
use App\Modules\Files\Files;
use App\Modules\Knowledge\KnowledgeIndex;
use App\Modules\Operations\Exports;
use App\Support\Database\Records as R;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\DB;

/** DB 是權威任務；Redis 只送 ID，worker 的每次完成必須比對 lease fencing token。 */
final class Tasks
{
    public function enqueue(string $type, string $entity, string $queue, string $dedupe, ?string $deadline = null): array
    {
        $workspace = app(TenantDatabase::class)->currentWorkspaceId();
        $id = R::id();
        DB::table('async_tasks')->insertOrIgnore(['id' => $id, 'workspace_id' => $workspace, 'type' => $type, 'entity_id' => $entity, 'dedupe_key' => $dedupe, 'queue_name' => $queue, 'max_attempts' => $queue === 'ai' ? 2 : 3, 'deadline_at' => $deadline, 'not_before' => $queue === 'ai' ? now()->addMilliseconds(800) : now(), 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('async_tasks')->where('dedupe_key', $dedupe)->first();

        return self::dto($row);
    }

    public static function dto(object $t): array
    {
        return ['id' => $t->id, 'type' => $t->type, 'state' => $t->state, 'error_code' => $t->error_code, 'created_at' => R::date($t->created_at)];
    }

    public function validLease(string $id, string $token): bool
    {
        return DB::table('async_tasks')->where('id', $id)->where('state', 'running')->where('lease_token', $token)->where('lease_expires_at', '>', now())->where(fn ($q) => $q->whereNull('deadline_at')->orWhere('deadline_at', '>', now()))->exists();
    }

    public function run(string $id, string $workspace): void
    {
        app(TenantDatabase::class)->withinWorkspace($workspace, function () use ($id): void {
            $task = DB::transaction(function () use ($id): ?object {
                $t = DB::table('async_tasks')->where('id', $id)->lockForUpdate()->first();
                if ($t === null || in_array($t->state, ['succeeded', 'failed', 'cancelled'], true) || ($t->state === 'running' && strtotime($t->lease_expires_at) > time()) || ($t->not_before && strtotime($t->not_before) > time()) || ($t->deadline_at && strtotime($t->deadline_at) <= time())) {
                    return null;
                }
                if ($t->attempt_count >= $t->max_attempts) {
                    DB::table('async_tasks')->where('id', $id)->update(['state' => 'failed', 'lease_token' => null, 'lease_expires_at' => null, 'error_code' => 'attempts_exhausted']);

                    return null;
                }
                $token = R::id();
                DB::table('async_tasks')->where('id', $id)->update(['state' => 'running', 'lease_token' => $token, 'lease_expires_at' => now()->addSeconds((int) config('yacs.queues.lease_seconds.'.$t->queue_name, 45)), 'attempt_count' => (int) $t->attempt_count + 1, 'started_at' => now(), 'updated_at' => now()]);

                return DB::table('async_tasks')->where('id', $id)->first();
            });
            if ($task === null) {
                return;
            }
            try {
                match ($task->type) {
                    'file.scan' => app(Files::class)->scan($task->entity_id, $task->id, $task->lease_token),
                    'knowledge.index' => app(KnowledgeIndex::class)->index($task->entity_id, $task->id, $task->lease_token),
                    'ai.generate' => app(Autopilot::class)->generate($task->entity_id, $task->id, $task->lease_token),
                    'data.export' => app(Exports::class)->build($task->entity_id, $task->id, $task->lease_token),
                    default => throw new \LogicException('Unknown task type.'),
                };
                DB::table('async_tasks')->where('id', $id)->where('lease_token', $task->lease_token)->where('lease_expires_at', '>', now())->update(['state' => 'succeeded', 'lease_token' => null, 'lease_expires_at' => null, 'finished_at' => now(), 'updated_at' => now()]);
            } catch (\Throwable $e) {
                $code = $e instanceof ApiException ? $e->errorCode->value : 'task_failed';
                DB::table('async_tasks')->where('id', $id)->where('lease_token', $task->lease_token)->update(['state' => $task->attempt_count < $task->max_attempts ? 'pending' : 'failed', 'lease_token' => null, 'lease_expires_at' => null, 'error_code' => $code, 'not_before' => now()->addSeconds(30), 'updated_at' => now()]);
                // 外部 payload/headers 不記錄到 log；只保留可公開錯誤碼與 task id。
                logger()->warning('YACS task failed', ['task_id' => $id, 'code' => $code]);
            }
        });
    }

    public function dispatch(int $limit = 50): int
    {
        $tenant = app(TenantDatabase::class);
        $rows = $tenant->asSystem(fn () => DB::table('async_tasks')->where(fn ($q) => $q->where('state', 'pending')->orWhere(fn ($q) => $q->where('state', 'queued')->where('queued_at', '<', now()->subMinutes(5)))->orWhere(fn ($q) => $q->where('state', 'running')->where('lease_expires_at', '<', now())))->where(fn ($q) => $q->whereNull('not_before')->orWhere('not_before', '<=', now()))->orderBy('created_at')->limit($limit)->get());
        foreach ($rows as $row) {
            try {
                $tenant->withinWorkspace($row->workspace_id, function () use ($row): void {
                    DB::table('async_tasks')->where('id', $row->id)->where('state', 'pending')->update(['state' => 'queued', 'queued_at' => now()]);
                    WorkTask::dispatch($row->id, $row->workspace_id, $row->queue_name)->onConnection($row->queue_name)->onQueue($row->queue_name);
                });
            } catch (\Throwable) {
                $tenant->withinWorkspace($row->workspace_id, fn () => DB::table('async_tasks')->where('id', $row->id)->where('state', 'queued')->update(['state' => 'pending']));
            }
        }

        return count($rows);
    }
}
