<?php

declare(strict_types=1);

namespace App\Modules\Conversations;

use App\Modules\AccessControl\Authorizer;
use App\Modules\AccessControl\Permission;
use App\Modules\AccessControl\ScopeResolver;
use App\Modules\AccessControl\StaffActor;
use App\Modules\Identity\Visitor\VisitorActor;
use App\Modules\Integrations\IntegrationActor;
use App\Support\Database\Records;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use App\Support\Http\Principal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** 所有列表與單筆操作使用相同 scope；不能以知道 UUID 取代授權。 */
final class ConversationPolicy
{
    public function query(Principal $actor): Builder
    {
        $query = DB::table('conversations')->where('workspace_id', $actor->workspaceId());
        if ($actor instanceof VisitorActor) {
            return $query->where('inbox_id', $actor->inboxId())->where('contact_id', $actor->contactId());
        }
        if ($actor instanceof IntegrationActor) {
            return $query->whereIn('brand_id', Records::json($actor->client->brand_scope))->whereIn('inbox_id', Records::json($actor->client->inbox_scope))->whereIn('contact_id', DB::table('contact_identities')->where('issuer', $actor->client->contact_issuer)->select('contact_id'));
        }
        if (! $actor instanceof StaffActor) {
            throw new ApiException(ErrorCode::Forbidden);
        }
        $auth = app(Authorizer::class);
        $scope = app(ScopeResolver::class);
        $allowed = DB::table('inboxes')->get(['id'])->filter(fn ($inbox) => $auth->can(Permission::ConversationRead, $scope->forInbox($inbox->id)))->pluck('id')->all();
        $assist = array_values(array_filter($allowed, fn ($id) => $auth->can(Permission::ConversationAssistOther, $scope->forInbox($id))));

        return $query->whereIn('inbox_id', $allowed)->where(fn (Builder $q) => $q->whereNull('assignee_id')->orWhere('assignee_id', $actor->membershipId())->orWhereIn('inbox_id', $assist)->orWhere('status', 'resolved'));
    }

    public function get(Principal $actor, string $id, bool $lock = false): object
    {
        if (! Str::isUuid($id)) {
            throw new ApiException(ErrorCode::NotFound);
        }
        $query = $this->query($actor)->where('id', $id);
        $c = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($c === null) {
            throw new ApiException(ErrorCode::NotFound);
        }

        return $c;
    }

    public function write(StaffActor $actor, object $c, Permission $permission, bool $ownership = true): void
    {
        $scope = app(ScopeResolver::class)->forConversation($c->inbox_id, $c->team_id);
        $auth = app(Authorizer::class);
        $auth->authorize($permission, $scope);
        if ($ownership && $c->assignee_id !== $actor->membershipId() && ! $auth->can(Permission::ConversationAssistOther, $scope)) {
            throw new ApiException(ErrorCode::Forbidden);
        }
    }

    public function version(object $c, string $expected): void
    {
        if ((string) $c->version !== $expected) {
            throw new ApiException(ErrorCode::VersionConflict, null, ['current_version' => (string) $c->version]);
        }
    }
}
