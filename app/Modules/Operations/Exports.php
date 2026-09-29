<?php

declare(strict_types=1);

namespace App\Modules\Operations;

use App\Modules\AccessControl\Authorizer;
use App\Modules\AccessControl\FreshStaff;
use App\Modules\AccessControl\Permission;
use App\Modules\Conversations\ConversationResource;
use App\Modules\Files\Files;
use App\Modules\Tasks\Tasks;
use App\Support\Database\Records as R;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

final class Exports
{
    public function build(string $id, string $task, string $lease): void
    {
        $export = DB::table('export_jobs')->where('id', $id)->first();
        if ($export === null || $export->state === 'ready') {
            return;
        }
        $staff = FreshStaff::resolve($export->requested_by);
        if ($staff === null) {
            throw new ApiException(ErrorCode::Forbidden);
        }
        $auth = new Authorizer($staff);
        $auth->authorize(Permission::ExportCreate);
        $auth->authorize($export->kind === 'audit' ? Permission::AuditRead : Permission::ConversationRead);
        $input = R::json($export->query);
        if ($export->kind === 'report_summary') {
            $rows = Http\Controllers\OperationsController::report($input);
        } elseif ($export->kind === 'audit') {
            $rows = DB::table('audit_logs')->whereBetween('created_at', [$input['from'], $input['to']])->limit(10000)->get()->all();
        } else {
            $q = DB::table('conversations')->whereBetween('created_at', [$input['from'], $input['to']]);
            foreach (['brand_id', 'inbox_id'] as $field) {
                if (isset($input[$field])) {
                    $q->where($field, $input[$field]);
                }
            }
            $rows = $q->limit(10000)->get()->map(fn ($c) => ConversationResource::conversation($c, true))->all();
        }
        $body = R::encode($rows);
        $file = R::id();
        $key = $export->workspace_id.'/export/'.$file;
        app(Files::class)->disk()->put($key, $body);
        DB::transaction(function () use ($export, $file, $key, $body, $task, $lease): void {
            DB::table('async_tasks')->where('id', $task)->lockForUpdate()->first();
            if (! app(Tasks::class)->validLease($task, $lease)) {
                app(Files::class)->disk()->delete($key);

                return;
            }
            DB::table('export_jobs')->where('id', $export->id)->lockForUpdate()->first();
            DB::table('files')->insert(['id' => $file, 'workspace_id' => $export->workspace_id, 'owner_type' => 'staff', 'owner_id' => $export->requested_by, 'purpose' => 'export', 'object_key' => $key, 'storage_disk' => (string) config('yacs.files.disk'), 'storage_encryption' => Files::encryption((string) config('yacs.files.disk')), 'original_name' => 'yacs-export.json', 'declared_mime' => 'application/json', 'detected_mime' => 'application/json', 'declared_bytes' => strlen($body), 'bytes' => strlen($body), 'scan_state' => 'clean', 'created_at' => now(), 'completed_at' => now()]);
            DB::table('export_jobs')->where('id', $export->id)->update(['state' => 'ready', 'file_id' => $file, 'expires_at' => now()->addDay(), 'updated_at' => now()]);
            DB::table('async_tasks')->where('id', $export->task_id)->update(['result_safe' => R::encode(['file_id' => $file])]);
        });
    }
}
