<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Http\Controllers;

use App\Modules\AccessControl\Authorizer;
use App\Modules\AccessControl\Permission;
use App\Modules\AccessControl\ScopeResolver;
use App\Modules\AccessControl\StaffActor;
use App\Modules\Audit\AuditActor;
use App\Modules\Audit\AuditLogger;
use App\Modules\Workspaces\Http\Resources\InboxSettings;
use App\Modules\Workspaces\Http\Resources\OpsResources;
use App\Modules\Workspaces\Models\Inbox;
use App\Modules\Workspaces\Support\OriginNormalizer;
use App\Support\Database\Cas;
use App\Support\Http\ApiException;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use App\Support\Http\Input;
use App\Support\Http\KeysetPaginator;
use App\Support\Security\Tokens;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 收件匣設定（OpenAPI: listInbox / createInbox / getInbox / updateInbox）。
 *
 * - 讀取：在該收件匣範圍有 inbox.manage 或 conversation.read 的成員。
 * - 寫入：inbox.manage（範圍涵蓋收件匣或其品牌）。
 * - allowed_origins 只存正規化後的 scheme://host[:port]；team_ids 決定可服務的團隊。
 */
final class InboxController
{
    public function index(Request $request, StaffActor $actor, Authorizer $authz): JsonResponse
    {
        $params = Input::validate($request, [...Input::pageRules(), 'brand_id' => Input::uuid(false)]);
        $query = Inbox::query()->forWorkspace($actor->workspaceId());
        if (isset($params['brand_id'])) {
            $query->where('brand_id', $params['brand_id']);
        }
        $this->restrictToVisible($query, $authz);

        return ApiResponse::page(KeysetPaginator::paginate(
            $query, [['name', 'asc'], ['id', 'asc']],
            KeysetPaginator::clampLimit($params['limit'] ?? null), $params['cursor'] ?? null,
            static fn (Inbox $i): array => OpsResources::inbox($i),
        ));
    }

    public function show(string $workspace_id, string $inbox_id, StaffActor $actor, Authorizer $authz, ScopeResolver $scopes): JsonResponse
    {
        $inbox = $this->find($actor, $inbox_id);
        $scope = $scopes->forInbox($inbox->id);
        if (! $authz->can(Permission::InboxManage, $scope) && ! $authz->can(Permission::ConversationRead, $scope)) {
            throw ApiException::notFound();
        }

        return ApiResponse::data(OpsResources::inbox($inbox));
    }

    public function store(Request $request, StaffActor $actor, Authorizer $authz, ScopeResolver $scopes, AuditLogger $audit): JsonResponse
    {
        $data = Input::validate($request, [
            'brand_id' => Input::uuid(),
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'channel_type' => ['required', 'string', 'in:web,generic_api'],
            'status' => ['required', 'string', 'in:active,disabled'],
            'ai_mode' => ['required', 'string', 'in:disabled,assist_only,auto_reply'],
            'ai_profile_id' => Input::uuid(false, true),
            'business_hours_id' => Input::uuid(false, true),
            'allowed_origins' => ['sometimes', 'array', 'max:50'],
            'allowed_origins.*' => ['string', 'max:300'],
            'team_ids' => ['sometimes', 'array', 'max:100'],
            'team_ids.*' => ['string', 'uuid', 'distinct'],
            ...InboxSettings::rules(),
        ]);
        $brandScope = $scopes->forBrand($data['brand_id']);
        if ($brandScope === null) {
            throw new ApiException(ErrorCode::ValidationFailed, null, ['fields' => ['brand_id' => ['not_found']]]);
        }
        $authz->authorize(Permission::InboxManage, $brandScope);
        $origins = $this->normalizeOrigins($data['allowed_origins'] ?? []);
        $this->assertReferences($actor, $data);

        $inbox = new Inbox;
        DB::transaction(function () use ($inbox, $data, $actor, $origins, $audit): void {
            $inbox->forceFill([
                'workspace_id' => $actor->workspaceId(),
                'brand_id' => $data['brand_id'],
                'name' => $data['name'],
                'public_key' => Tokens::publicKey('ibx_'),
                'channel_type' => $data['channel_type'],
                'status' => $data['status'],
                'ai_mode' => $data['ai_mode'],
                'ai_profile_id' => $data['ai_profile_id'] ?? null,
                'business_hours_id' => $data['business_hours_id'] ?? null,
                'settings' => InboxSettings::sanitize((array) ($data['settings'] ?? [])),
            ])->save();
            $this->syncOrigins($inbox, $origins);
            $this->syncTeams($inbox, $data['team_ids'] ?? []);
            $audit->record($actor->workspaceId(), AuditActor::staff($actor), 'inbox.created', 'inbox', $inbox->id, null, OpsResources::inbox($inbox));
        });

        return ApiResponse::data(OpsResources::inbox($inbox->refresh()), 201);
    }

    public function update(Request $request, string $workspace_id, string $inbox_id, StaffActor $actor, Authorizer $authz, ScopeResolver $scopes, AuditLogger $audit): JsonResponse
    {
        $inbox = $this->find($actor, $inbox_id);
        $authz->authorize(Permission::InboxManage, $scopes->forInbox($inbox->id));
        $data = Input::validate($request, [
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'status' => ['sometimes', 'string', 'in:active,disabled'],
            'ai_mode' => ['sometimes', 'string', 'in:disabled,assist_only,auto_reply'],
            'ai_profile_id' => Input::uuid(false, true),
            'business_hours_id' => Input::uuid(false, true),
            'allowed_origins' => ['sometimes', 'array', 'max:50'],
            'allowed_origins.*' => ['string', 'max:300'],
            'team_ids' => ['sometimes', 'array', 'max:100'],
            'team_ids.*' => ['string', 'uuid', 'distinct'],
            ...InboxSettings::rules(),
            'expected_version' => Input::seq(),
        ]);
        $origins = array_key_exists('allowed_origins', $data) ? $this->normalizeOrigins($data['allowed_origins']) : null;
        $this->assertReferences($actor, $data);

        DB::transaction(function () use ($inbox, $data, $origins, $actor, $audit): void {
            $before = OpsResources::inbox($inbox);
            $changes = array_intersect_key($data, array_flip(['name', 'status', 'ai_mode', 'ai_profile_id', 'business_hours_id']));
            if (array_key_exists('settings', $data)) {
                $changes['settings'] = json_encode(InboxSettings::sanitize(array_merge((array) $inbox->settings, (array) $data['settings'])), JSON_UNESCAPED_UNICODE);
            }
            Cas::update($inbox, $data['expected_version'], $changes);
            if ($origins !== null) {
                $this->syncOrigins($inbox, $origins);
            }
            if (array_key_exists('team_ids', $data)) {
                $this->syncTeams($inbox, $data['team_ids']);
            }
            $audit->record($actor->workspaceId(), AuditActor::staff($actor), 'inbox.updated', 'inbox', $inbox->id, $before, OpsResources::inbox($inbox));
        });

        return ApiResponse::data(OpsResources::inbox($inbox));
    }

    private function find(StaffActor $actor, string $inboxId): Inbox
    {
        $inbox = Input::isUuid($inboxId) ? Inbox::query()->forWorkspace($actor->workspaceId())->find($inboxId) : null;
        if ($inbox === null) {
            throw ApiException::notFound();
        }

        return $inbox;
    }

    /**
     * @param  Builder<Inbox>  $query
     */
    private function restrictToVisible($query, Authorizer $authz): void
    {
        $grants = [...$authz->grantsFor(Permission::InboxManage), ...$authz->grantsFor(Permission::ConversationRead)];
        foreach ($grants as $grant) {
            if ($grant->scopeType === 'workspace') {
                return;
            }
        }
        $query->where(function ($q) use ($grants): void {
            $q->whereRaw('false');
            foreach ($grants as $grant) {
                match ($grant->scopeType) {
                    'brand' => $q->orWhere('brand_id', $grant->scopeId),
                    'inbox' => $q->orWhere('id', $grant->scopeId),
                    'team' => $q->orWhereIn('id', DB::table('inbox_teams')->select('inbox_id')->where('team_id', $grant->scopeId)),
                    default => null,
                };
            }
        });
    }

    /**
     * @param  list<string>  $origins
     * @return list<string>
     */
    private function normalizeOrigins(array $origins): array
    {
        $normalized = [];
        foreach ($origins as $i => $origin) {
            $value = OriginNormalizer::normalize((string) $origin);
            if ($value === null) {
                throw new ApiException(ErrorCode::ValidationFailed, null, ['fields' => ["allowed_origins.{$i}" => ['invalid_origin']]]);
            }
            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertReferences(StaffActor $actor, array $data): void
    {
        $w = $actor->workspaceId();
        if (! empty($data['business_hours_id']) && ! DB::table('business_hours')->where('workspace_id', $w)->where('id', $data['business_hours_id'])->exists()) {
            throw new ApiException(ErrorCode::ValidationFailed, null, ['fields' => ['business_hours_id' => ['not_found']]]);
        }
        if (! empty($data['ai_profile_id']) && ! DB::table('ai_profiles')->where('workspace_id', $w)->where('id', $data['ai_profile_id'])->exists()) {
            throw new ApiException(ErrorCode::ValidationFailed, null, ['fields' => ['ai_profile_id' => ['not_found']]]);
        }
        $teamIds = $data['team_ids'] ?? [];
        if ($teamIds !== [] && DB::table('teams')->where('workspace_id', $w)->whereIn('id', $teamIds)->count() !== count($teamIds)) {
            throw new ApiException(ErrorCode::ValidationFailed, null, ['fields' => ['team_ids' => ['not_found']]]);
        }
    }

    /**
     * @param  list<string>  $origins
     */
    private function syncOrigins(Inbox $inbox, array $origins): void
    {
        DB::table('inbox_origins')->where('inbox_id', $inbox->id)->whereNotIn('origin', $origins)->delete();
        $now = CarbonImmutable::now();
        DB::table('inbox_origins')->insertOrIgnore(array_map(static fn (string $o): array => [
            'id' => (string) Str::uuid7(),
            'workspace_id' => $inbox->workspace_id,
            'inbox_id' => $inbox->id,
            'origin' => $o,
            'created_at' => $now,
        ], $origins));
    }

    /**
     * @param  list<string>  $teamIds
     */
    private function syncTeams(Inbox $inbox, array $teamIds): void
    {
        DB::table('inbox_teams')->where('inbox_id', $inbox->id)->whereNotIn('team_id', $teamIds)->delete();
        $now = CarbonImmutable::now();
        DB::table('inbox_teams')->insertOrIgnore(array_map(static fn (string $t): array => [
            'id' => (string) Str::uuid7(),
            'workspace_id' => $inbox->workspace_id,
            'inbox_id' => $inbox->id,
            'team_id' => $t,
            'created_at' => $now,
        ], $teamIds));
    }
}
