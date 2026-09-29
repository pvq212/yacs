<?php

declare(strict_types=1);

namespace App\Modules\Files;

use App\Modules\AccessControl\Authorizer;
use App\Modules\AccessControl\Permission;
use App\Modules\AccessControl\StaffActor;
use App\Modules\Conversations\ConversationPolicy;
use App\Modules\Files\Contracts\FileScanner;
use App\Modules\Identity\Visitor\VisitorActor;
use App\Modules\Tasks\Tasks;
use App\Support\Database\Records as R;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use App\Support\Http\Principal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

final class Files
{
    public function disk(?object $file = null): ObjectStorage
    {
        $disk = (string) ($file->storage_disk ?? config('yacs.files.disk'));
        $encrypted = $file !== null ? ($file->storage_encryption ?? null) === 'envelope_v1' : self::encryption($disk) !== null;

        return new ObjectStorage(Storage::disk($disk), $encrypted);
    }

    public static function encryption(string $disk): ?string
    {
        return in_array($disk, ['s3', 'r2'], true) || config('filesystems.disks.'.$disk.'.driver') === 's3' ? 'envelope_v1' : null;
    }

    public function ticket(Principal $actor, array $input): array
    {
        if (! ($actor instanceof VisitorActor || $actor instanceof StaffActor)) {
            throw new ApiException(ErrorCode::Forbidden);
        }
        $visitor = $actor instanceof VisitorActor;
        if ($visitor && $input['purpose'] !== 'chat') {
            throw new ApiException(ErrorCode::Forbidden);
        }
        if (! $visitor) {
            app(Authorizer::class)->authorizeAnywhere($input['purpose'] === 'knowledge' ? Permission::KnowledgeEdit : Permission::ConversationReply);
        }
        $limit = $input['purpose'] === 'knowledge' ? 20971520 : 10485760;
        if ($input['bytes'] > $limit) {
            throw new ApiException(ErrorCode::PayloadTooLarge);
        }
        if ($visitor) {
            $inbox = DB::table('inboxes')->where('id', $actor->inboxId())->first();
            if ((R::json($inbox->settings)['require_verified_for_attachments'] ?? false) && $actor->session->identity_level !== 'verified') {
                throw new ApiException(ErrorCode::Forbidden);
            }
        }
        $recent = DB::table('files')->where('owner_id', $actor instanceof VisitorActor ? $actor->session->id : $actor->membershipId())->where('created_at', '>', now()->subHour())->sum('declared_bytes');
        if ($recent + $input['bytes'] > 104857600) {
            throw new ApiException(ErrorCode::RateLimited);
        }
        $id = R::id();
        $key = $actor->workspaceId().'/'.$input['purpose'].'/'.now()->format('Y/m').'/'.$id;
        DB::table('files')->insert(['id' => $id, 'workspace_id' => $actor->workspaceId(), 'brand_id' => $visitor ? $actor->session->brand_id : null, 'owner_type' => $visitor ? 'visitor' : 'staff', 'owner_id' => $actor instanceof VisitorActor ? $actor->session->id : $actor->membershipId(), 'contact_id' => $visitor ? $actor->contactId() : null, 'purpose' => $input['purpose'], 'object_key' => $key, 'storage_disk' => (string) config('yacs.files.disk'), 'storage_encryption' => Files::encryption((string) config('yacs.files.disk')), 'original_name' => basename(str_replace('\\', '/', $input['filename'])), 'declared_mime' => $input['content_type'], 'declared_bytes' => $input['bytes'], 'created_at' => now()]);
        $expires = now()->addMinutes(10);

        return ['file_id' => $id, 'upload_url' => URL::temporarySignedRoute('uploadBlob', $expires, ['file' => $id]), 'method' => 'PUT', 'headers' => ['Content-Type' => 'application/octet-stream'], 'expires_at' => $expires->toISOString()];
    }

    public function authorize(Principal $actor, string $id, bool $ownerOnly = false): object
    {
        if (! ($actor instanceof VisitorActor || $actor instanceof StaffActor)) {
            throw new ApiException(ErrorCode::Forbidden);
        }
        $f = DB::table('files')->where('id', $id)->whereNull('deleted_at')->first();
        if ($f === null) {
            throw new ApiException(ErrorCode::NotFound);
        }
        if ($f->purpose === 'export' && $actor instanceof VisitorActor) {
            throw new ApiException(ErrorCode::NotFound);
        }
        if ($f->purpose === 'export') {
            app(Authorizer::class)->authorize(Permission::ExportCreate);
            $job = DB::table('export_jobs')->where('file_id', $f->id)->where('requested_by', $actor->membershipId())->where('expires_at', '>', now())->first();
            if ($job === null) {
                throw new ApiException(ErrorCode::Forbidden);
            } app(Authorizer::class)->authorize($job->kind === 'audit' ? Permission::AuditRead : Permission::ConversationRead);
        }
        if ($actor instanceof VisitorActor) {
            if ($f->brand_id !== $actor->session->brand_id || $f->purpose !== 'chat') {
                throw new ApiException(ErrorCode::NotFound);
            }
            if ($f->contact_id === $actor->contactId()) {
                return $f;
            }
            if ($ownerOnly) {
                throw new ApiException(ErrorCode::Forbidden);
            }
            $linked = DB::table('message_attachments as a')->join('messages as m', 'm.id', '=', 'a.message_id')->join('conversations as c', 'c.id', '=', 'm.conversation_id')->where('a.file_id', $f->id)->where('m.visibility', 'public')->where('c.contact_id', $actor->contactId())->where('c.inbox_id', $actor->inboxId())->exists();
            if (! $linked) {
                throw new ApiException(ErrorCode::NotFound);
            }
        } else {
            $owned = $f->owner_type === 'staff' && $f->owner_id === $actor->membershipId();
            if ($f->purpose === 'export') {
                return $f;
            }
            if ($owned && ! DB::table('message_attachments')->where('file_id', $id)->exists() && $f->purpose === 'chat') {
                app(Authorizer::class)->authorizeAnywhere(Permission::ConversationReply);

                return $f;
            }
            if ($ownerOnly && ! $owned) {
                throw new ApiException(ErrorCode::Forbidden);
            }
            if ($f->purpose === 'knowledge') {
                if ($owned) {
                    app(Authorizer::class)->authorizeAnywhere(Permission::KnowledgeEdit);
                } else {
                    app(Authorizer::class)->authorize(Permission::KnowledgeEdit);
                }

                return $f;
            }
            $conversations = app(ConversationPolicy::class)->query($actor)->select('conversations.id');
            if (! DB::table('message_attachments as a')->join('messages as m', 'm.id', '=', 'a.message_id')->where('a.file_id', $id)->whereIn('m.conversation_id', $conversations)->exists()) {
                throw new ApiException(ErrorCode::NotFound);
            }
        }

        return $f;
    }

    public function complete(object $f): object
    {
        return DB::transaction(function () use ($f): object {
            $file = DB::table('files')->where('id', $f->id)->lockForUpdate()->first();
            if ($file->scan_state !== 'pending_upload') {
                return $file;
            }
            if (! $this->disk($file)->exists($file->object_key)) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            DB::table('files')->where('id', $file->id)->update(['scan_state' => 'quarantined']);
            app(Tasks::class)->enqueue('file.scan', $file->id, 'core', 'scan:'.$file->id);

            return DB::table('files')->where('id', $file->id)->first();
        });
    }

    public function scan(string $id, string $task, string $lease): void
    {
        $file = DB::table('files')->where('id', $id)->where('scan_state', 'quarantined')->whereNull('deleted_at')->first();
        if ($file === null) {
            return;
        }
        $result = app(FileScanner::class)->scan($this->disk($file)->get($file->object_key), $file);
        DB::transaction(function () use ($file, $result, $task, $lease): void {
            $locked = DB::table('files')->where('id', $file->id)->lockForUpdate()->first();
            DB::table('async_tasks')->where('id', $task)->lockForUpdate()->first();
            if ($locked->scan_state !== 'quarantined' || $locked->deleted_at !== null || ! app(Tasks::class)->validLease($task, $lease)) {
                return;
            }
            if ($result['rejection'] === null && ! $this->disk($file)->put($file->object_key, $result['body'])) {
                throw new \RuntimeException('storage_failed');
            }
            DB::table('files')->where('id', $file->id)->update(['scan_state' => $result['rejection'] ? 'rejected' : 'clean', 'rejection_code' => $result['rejection'], 'detected_mime' => $result['mime'], 'bytes' => strlen($result['body']), 'sha256' => hash('sha256', $result['body']), 'image_width' => $result['width'], 'image_height' => $result['height'], 'completed_at' => now()]);
        });
    }

    public static function dto(object $f): array
    {
        return ['id' => $f->id, 'name' => $f->original_name, 'mime' => $f->detected_mime ?? $f->declared_mime, 'bytes' => (int) ($f->bytes ?? $f->declared_bytes), 'state' => $f->scan_state, 'error_code' => $f->rejection_code];
    }
}
