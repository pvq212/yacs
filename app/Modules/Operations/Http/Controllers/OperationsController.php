<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Controllers;

use App\Modules\AccessControl\Authorizer;
use App\Modules\AccessControl\Permission;
use App\Modules\AccessControl\ResourceScope;
use App\Modules\AccessControl\StaffActor;
use App\Modules\Tasks\Tasks;
use App\Support\Database\Records as R;
use App\Support\Http\ApiException;
use App\Support\Http\ApiResponse;
use App\Support\Http\ContractRequest;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

final class OperationsController
{
    public function __invoke(ContractRequest $request): mixed
    {
        $actor = app(StaffActor::class);
        $auth = app(Authorizer::class);
        $input = $request->payload();
        $op = $request->route()->getName();
        if (str_contains($op, 'ApiClient')) {
            $auth->authorize(Permission::IntegrationManage);
            if ($op === 'listApiClients') {
                return ApiResponse::list(DB::table('api_clients')->orderByDesc('id')->get()->map(self::client(...))->all());
            }
            if ($op === 'revokeApiClient') {
                DB::table('api_clients')->where('id', $request->route('api_client_id'))->update(['status' => 'revoked', 'revoked_at' => now()]);

                return ApiResponse::noContent();
            }
            $allowed = ['contacts.upsert', 'conversations.create', 'conversations.read', 'messages.write', 'conversations.handoff'];
            if (array_diff($input['scopes'], $allowed) !== [] || $input['brand_ids'] === [] || $input['inbox_ids'] === []) {
                throw new ApiException(ErrorCode::ValidationFailed);
            }
            foreach ($input['inbox_ids'] as $id) {
                if (! DB::table('inboxes')->where('id', $id)->whereIn('brand_id', $input['brand_ids'])->exists()) {
                    throw new ApiException(ErrorCode::NotFound);
                }
            }
            foreach ($input['allowed_ips'] ?? [] as $ip) {
                if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                    throw new ApiException(ErrorCode::ValidationFailed);
                }
            }
            $id = R::id();
            $token = 'yacs_'.bin2hex(random_bytes(32));
            DB::table('api_clients')->insert(['id' => $id, 'workspace_id' => $actor->workspaceId(), 'name' => $input['name'], 'token_prefix' => substr($token, 0, 14), 'token_hash' => hash('sha256', $token), 'scopes' => R::encode($input['scopes']), 'brand_scope' => R::encode($input['brand_ids']), 'inbox_scope' => R::encode($input['inbox_ids']), 'allowed_ips' => R::encode($input['allowed_ips'] ?? []), 'contact_issuer' => 'api:'.$id, 'expires_at' => $input['expires_at'] ?? null, 'created_by' => $actor->membershipId(), 'created_at' => now(), 'updated_at' => now()]);

            return ApiResponse::data(['client' => self::client(DB::table('api_clients')->where('id', $id)->first()), 'token' => $token], 201);
        }
        if (str_contains($op, 'WebhookDeliver')) {
            $auth->authorize(Permission::IntegrationManage);
            if ($op === 'listWebhookDeliveries') {
                return ApiResponse::list(DB::table('webhook_deliveries')->orderByDesc('id')->limit(100)->get()->map(fn ($d) => ['id' => $d->id, 'event_id' => $d->event_id, 'state' => $d->state === 'delivering' ? 'sending' : $d->state, 'attempt_count' => (int) $d->attempt_count, 'last_http_status' => $d->last_status, 'next_attempt_at' => R::date($d->next_attempt_at)])->all());
            }
            DB::table('webhook_deliveries')->where('id', $request->route('delivery_id'))->whereIn('state', ['failed', 'paused'])->update(['state' => 'pending', 'next_attempt_at' => now()]);

            return ApiResponse::noContent();
        }
        if ($op === 'getReportSummary') {
            $auth->authorize(Permission::ReportRead);
            if (isset($input['from'], $input['to']) && strtotime($input['from']) > strtotime($input['to'])) {
                throw new ApiException(ErrorCode::ValidationFailed);
            }

            return ApiResponse::data(self::report($input));
        }
        if ($op === 'createExport') {
            $auth->authorize(Permission::ExportCreate);
            $auth->authorize($input['kind'] === 'audit' ? Permission::AuditRead : Permission::ConversationRead);
            $id = R::id();
            DB::table('export_jobs')->insert(['id' => $id, 'workspace_id' => $actor->workspaceId(), 'requested_by' => $actor->membershipId(), 'kind' => $input['kind'], 'scope_snapshot' => R::encode(['membership_id' => $actor->membershipId()]), 'query' => R::encode($input), 'created_at' => now(), 'updated_at' => now()]);
            $task = app(Tasks::class)->enqueue('data.export', $id, 'core', 'export:'.$id);
            DB::table('export_jobs')->where('id', $id)->update(['task_id' => $task['id']]);

            return ApiResponse::data($task, 202);
        }
        if ($op === 'getTask') {
            $task = DB::table('async_tasks')->where('id', $request->route('task_id'))->first();
            if ($task === null) {
                throw new ApiException(ErrorCode::NotFound);
            }
            if ($task->type === 'knowledge.index') {
                $brand = DB::table('knowledge_versions as v')->join('knowledge_documents as d', 'd.id', '=', 'v.document_id')->join('knowledge_bases as k', 'k.id', '=', 'd.knowledge_base_id')->where('v.id', $task->entity_id)->value('k.brand_id');
                if ($brand === null) {
                    throw new ApiException(ErrorCode::NotFound);
                }
                $auth->authorize(Permission::KnowledgeEdit, ResourceScope::brand($brand));
            } elseif ($task->type === 'data.export' && ! DB::table('export_jobs')->where('id', $task->entity_id)->where('requested_by', $actor->membershipId())->exists()) {
                throw new ApiException(ErrorCode::NotFound);
            } else {
                $auth->authorize(match ($task->type) {
                    'data.export' => Permission::ExportCreate, default => Permission::AiManage
                });
            }
            $dto = Tasks::dto($task);
            if ($task->result_safe !== null) {
                $dto['result'] = (object) R::json($task->result_safe);
            }

            return ApiResponse::data($dto);
        }
        throw new \LogicException('Unknown operations endpoint.');
    }

    public static function client(object $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'token_prefix' => $c->token_prefix, 'scopes' => R::json($c->scopes), 'brand_ids' => R::json($c->brand_scope), 'inbox_ids' => R::json($c->inbox_scope), 'expires_at' => R::date($c->expires_at), 'status' => $c->status];
    }

    public static function report(array $input): array
    {
        $from = $input['from'] ?? now()->subDays(30)->toISOString();
        $to = $input['to'] ?? now()->toISOString();
        $q = DB::table('conversations')->whereBetween('created_at', [$from, $to]);
        foreach (['brand_id', 'inbox_id'] as $field) {
            if (isset($input[$field])) {
                $q->where($field, $input[$field]);
            }
        }

        return ['from' => $from, 'to' => $to, 'total_conversations' => (clone $q)->count(), 'resolved_count' => (clone $q)->where('status', 'resolved')->count(), 'reopened_count' => (clone $q)->where('resolution_cycle', '>', 1)->count(), 'handoff_count' => DB::table('outbox_events')->where('event_type', 'conversation.handoff_requested')->whereIn('conversation_id', (clone $q)->select('id'))->distinct()->count('conversation_id'), 'csat_response_count' => DB::table('csat_responses')->whereIn('conversation_id', (clone $q)->select('id'))->count()];
    }
}
