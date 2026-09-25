<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Controllers;

use App\Modules\AccessControl\Authorizer;
use App\Modules\AccessControl\Permission;
use App\Modules\AccessControl\StaffActor;
use App\Modules\Audit\Models\AuditLog;
use App\Support\Database\Time;
use App\Support\Http\ApiResponse;
use App\Support\Http\Input;
use App\Support\Http\KeysetPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 稽核紀錄查詢（OpenAPI: listAuditLogs）。需 audit.read（workspace 範圍）。
 */
final class AuditLogController
{
    public function index(Request $request, StaffActor $actor, Authorizer $authz): JsonResponse
    {
        $authz->authorize(Permission::AuditRead);
        $params = Input::validate($request, [
            ...Input::pageRules(),
            'action' => ['sometimes', 'string', 'max:100'],
            'resource_type' => ['sometimes', 'string', 'max:64'],
            'resource_id' => Input::uuid(false),
            'actor_id' => Input::uuid(false),
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $query = AuditLog::query()->forWorkspace($actor->workspaceId());
        foreach (['action', 'resource_type', 'resource_id', 'actor_id'] as $field) {
            if (isset($params[$field])) {
                $query->where($field, $params[$field]);
            }
        }
        if (isset($params['from'])) {
            $query->where('created_at', '>=', $params['from']);
        }
        if (isset($params['to'])) {
            $query->where('created_at', '<', $params['to']);
        }

        return ApiResponse::page(KeysetPaginator::paginate(
            $query, [['created_at', 'desc'], ['id', 'desc']],
            KeysetPaginator::clampLimit($params['limit'] ?? null), $params['cursor'] ?? null,
            static fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'actor_type' => $log->actor_type,
                'actor_id' => $log->actor_id,
                'resource_type' => (string) $log->resource_type,
                'resource_id' => $log->resource_id,
                'reason' => $log->reason,
                'created_at' => Time::iso($log->created_at),
                'safe_changes' => (object) array_filter(['before' => $log->before_safe, 'after' => $log->after_safe, 'scope' => $log->scope ?: null]),
            ],
        ));
    }
}
