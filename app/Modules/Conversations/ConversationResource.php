<?php

declare(strict_types=1);

namespace App\Modules\Conversations;

use App\Support\Database\Records as R;
use Illuminate\Support\Facades\DB;

/** 公開與內部投影分開，禁止把完整資料庫列給訪客。 */
final class ConversationResource
{
    public static function conversation(object $c, bool $staff = false): array
    {
        $data = ['id' => $c->id, 'status' => $c->status, 'handling_mode' => $c->handling_mode, 'version' => (string) $c->version, 'resolution_cycle' => (int) $c->resolution_cycle, 'last_event_seq' => (string) ($staff ? $c->staff_event_seq : $c->public_event_seq)];
        if ($staff) {
            foreach (['workspace_id', 'brand_id', 'inbox_id', 'contact_id', 'assignee_id', 'team_id', 'resolution_code'] as $key) {
                $data[$key] = $c->$key;
            }
            foreach (['created_at', 'last_message_at', 'resolved_at'] as $key) {
                $data[$key] = R::date($c->$key);
            }
        }

        return $data;
    }

    public static function message(object $m, bool $staff = false): array
    {
        if (! $staff && $m->visibility !== 'public') {
            throw new \LogicException('Internal message in public projection.');
        }
        $attachments = DB::table('message_attachments as a')->join('files as f', 'f.id', '=', 'a.file_id')->where('a.message_id', $m->id)->where('f.scan_state', 'clean')->whereNull('f.deleted_at')->orderBy('a.position')->get(['f.id', 'f.original_name', 'f.detected_mime', 'f.bytes'])->map(fn ($f) => ['id' => $f->id, 'name' => $f->original_name, 'mime' => $f->detected_mime, 'bytes' => (int) $f->bytes])->all();
        $redacted = $m->redacted_at !== null || DB::table('message_redactions')->where('message_id', $m->id)->exists();
        $data = ['id' => $m->id, 'conversation_id' => $m->conversation_id, 'message_seq' => (string) $m->message_seq, 'client_message_id' => $m->client_message_id, 'author_type' => $m->author_type, 'kind' => $m->kind, 'body_text' => $redacted ? '' : $m->body_text, 'attachments' => $redacted ? [] : $attachments, 'created_at' => R::date($m->created_at), 'redacted' => $redacted];
        $metadata = R::json($m->metadata);
        if (isset($metadata['citations'])) {
            $data['citations'] = $metadata['citations'];
        }
        if ($staff) {
            $data['deliveries'] = DB::table('channel_deliveries')->where('message_id', $m->id)->get()->map(fn ($d) => ['id' => $d->id, 'state' => $d->state, 'error_code' => $d->last_error_code])->all();
            $data += ['visibility' => $m->visibility, 'staff_membership_id' => $m->staff_membership_id, 'ai_run_id' => $m->ai_run_id];
        }

        return $data;
    }
}
