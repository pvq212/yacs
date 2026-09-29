<?php

declare(strict_types=1);

namespace App\Modules\Files\Http\Controllers;

use App\Modules\AccessControl\StaffActor;
use App\Modules\Files\Files;
use App\Modules\Identity\Models\StaffSessionSecurity;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Visitor\VisitorActor;
use App\Modules\Identity\Visitor\VisitorSessions;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Models\WorkspaceMembership;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BlobController
{
    public function upload(Request $request, string $file): mixed
    {
        $tenant = app(TenantDatabase::class);
        $f = $tenant->asSystem(fn () => DB::table('files')->where('id', $file)->first());
        if ($f === null) {
            abort(404);
        }
        $tenant->enterWorkspace($f->workspace_id);
        if ($f->scan_state !== 'pending_upload') {
            abort(409);
        }
        $body = $request->getContent();
        if (strlen($body) !== (int) $f->declared_bytes || strlen($body) > 20971520) {
            abort(413);
        }
        // 原物件不可被完成後的舊上傳 ticket 覆寫；鎖檔案列直到寫入完成。
        DB::transaction(function () use ($file, $body): void {
            $locked = DB::table('files')->where('id', $file)->lockForUpdate()->first();
            if ($locked->scan_state !== 'pending_upload') {
                abort(409);
            }
            if (! app(Files::class)->disk($locked)->put($locked->object_key, $body)) {
                abort(503);
            }
        });

        return response('', 204);
    }

    public function download(Request $request, string $file): mixed
    {
        $tenant = app(TenantDatabase::class);
        $tenant->enterWorkspace((string) $request->query('workspace'));
        if ($request->query('principal') === 'visitor') {
            $session = DB::table('visitor_sessions')->where('id', $request->query('session'))->whereNull('revoked_at')->where('refresh_expires_at', '>', now())->first();
            if ($session === null) {
                abort(401);
            }
            app(VisitorSessions::class)->assertActive($session);
            $actor = new VisitorActor($session);
        } else {
            $security = StaffSessionSecurity::query()->find($request->query('session'));
            $membership = WorkspaceMembership::query()->whereKey($request->query('membership'))->where('status', 'active')->first();
            if ($security === null || ! $security->isUsable() || $membership === null || $security->user_id !== $membership->user_id) {
                abort(401);
            }
            $user = User::query()->findOrFail($membership->user_id);
            if (! $user->isActive()) {
                abort(401);
            }
            $actor = new StaffActor($user, Workspace::query()->findOrFail($membership->workspace_id), $membership, $security);
            app()->instance(StaffActor::class, $actor);
        }
        $f = app(Files::class)->authorize($actor, $file);
        if ($f->scan_state !== 'clean') {
            abort(403);
        }
        $stream = app(Files::class)->disk($f)->readStream($f->object_key);

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, $f->original_name, ['Content-Type' => $f->detected_mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store']);
    }
}
