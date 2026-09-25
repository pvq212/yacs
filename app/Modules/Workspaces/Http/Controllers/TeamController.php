<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Http\Controllers;

use App\Modules\AccessControl\Authorizer;
use App\Modules\AccessControl\Permission;
use App\Modules\AccessControl\StaffActor;
use App\Modules\Audit\AuditActor;
use App\Modules\Audit\AuditLogger;
use App\Modules\Workspaces\Http\Resources\OpsResources;
use App\Modules\Workspaces\Models\Team;
use App\Support\Database\Cas;
use App\Support\Http\ApiException;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use App\Support\Http\Input;
use App\Support\Http\KeysetPaginator;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 團隊（OpenAPI: listTeam / createTeam / getTeam / updateTeam）。
 *
 * 讀取：workspace 內任何成員（轉派與篩選需要團隊清單）；寫入：staff.manage。
 */
final class TeamController
{
    public function index(Request $request, StaffActor $actor): JsonResponse
    {
        $page = Input::validate($request, Input::pageRules());

        return ApiResponse::page(KeysetPaginator::paginate(
            Team::query()->forWorkspace($actor->workspaceId()), [['name', 'asc'], ['id', 'asc']],
            KeysetPaginator::clampLimit($page['limit'] ?? null), $page['cursor'] ?? null,
            static fn (Team $t): array => OpsResources::team($t),
        ));
    }

    public function show(string $workspace_id, string $team_id, StaffActor $actor): JsonResponse
    {
        return ApiResponse::data(OpsResources::team($this->find($actor, $team_id)));
    }

    public function store(Request $request, StaffActor $actor, Authorizer $authz, AuditLogger $audit): JsonResponse
    {
        $authz->authorize(Permission::StaffManage);
        $data = Input::validate($request, [
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'status' => ['required', 'string', 'in:active,disabled'],
            'member_ids' => ['sometimes', 'array', 'max:500'],
            'member_ids.*' => ['string', 'uuid', 'distinct'],
        ]);
        $this->assertMembers($actor, $data['member_ids'] ?? []);

        $team = new Team;
        try {
            DB::transaction(function () use ($team, $data, $actor, $audit): void {
                $team->forceFill(['workspace_id' => $actor->workspaceId(), 'name' => $data['name'], 'status' => $data['status']])->save();
                $this->syncMembers($team, $data['member_ids'] ?? []);
                $audit->record($actor->workspaceId(), AuditActor::staff($actor), 'team.created', 'team', $team->id, null, OpsResources::team($team));
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException(ErrorCode::ValidationFailed, null, ['fields' => ['name' => ['taken']]]);
        }

        return ApiResponse::data(OpsResources::team($team->refresh()), 201);
    }

    public function update(Request $request, string $workspace_id, string $team_id, StaffActor $actor, Authorizer $authz, AuditLogger $audit): JsonResponse
    {
        $authz->authorize(Permission::StaffManage);
        $team = $this->find($actor, $team_id);
        $data = Input::validate($request, [
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'status' => ['sometimes', 'string', 'in:active,disabled'],
            'member_ids' => ['sometimes', 'array', 'max:500'],
            'member_ids.*' => ['string', 'uuid', 'distinct'],
            'expected_version' => Input::seq(),
        ]);
        $this->assertMembers($actor, $data['member_ids'] ?? []);

        try {
            DB::transaction(function () use ($team, $data, $actor, $audit): void {
                $before = OpsResources::team($team);
                Cas::update($team, $data['expected_version'], array_intersect_key($data, array_flip(['name', 'status'])));
                if (array_key_exists('member_ids', $data)) {
                    $this->syncMembers($team, $data['member_ids']);
                }
                $audit->record($actor->workspaceId(), AuditActor::staff($actor), 'team.updated', 'team', $team->id, $before, OpsResources::team($team));
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException(ErrorCode::ValidationFailed, null, ['fields' => ['name' => ['taken']]]);
        }

        return ApiResponse::data(OpsResources::team($team));
    }

    private function find(StaffActor $actor, string $teamId): Team
    {
        $team = Input::isUuid($teamId) ? Team::query()->forWorkspace($actor->workspaceId())->find($teamId) : null;
        if ($team === null) {
            throw ApiException::notFound();
        }

        return $team;
    }

    /**
     * @param  list<string>  $memberIds
     */
    private function assertMembers(StaffActor $actor, array $memberIds): void
    {
        if ($memberIds !== [] && DB::table('workspace_memberships')->where('workspace_id', $actor->workspaceId())->whereIn('id', $memberIds)->count() !== count($memberIds)) {
            throw new ApiException(ErrorCode::ValidationFailed, null, ['fields' => ['member_ids' => ['not_found']]]);
        }
    }

    /**
     * @param  list<string>  $memberIds
     */
    private function syncMembers(Team $team, array $memberIds): void
    {
        DB::table('team_memberships')->where('team_id', $team->id)->whereNotIn('membership_id', $memberIds)->delete();
        $now = CarbonImmutable::now();
        DB::table('team_memberships')->insertOrIgnore(array_map(static fn (string $m): array => [
            'id' => (string) Str::uuid7(),
            'workspace_id' => $team->workspace_id,
            'team_id' => $team->id,
            'membership_id' => $m,
            'created_at' => $now,
        ], $memberIds));
    }
}
