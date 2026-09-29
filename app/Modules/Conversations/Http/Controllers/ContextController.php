<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Http\Controllers;

use App\Modules\AccessControl\Authorizer;
use App\Modules\AccessControl\Permission;
use App\Modules\AccessControl\ScopeResolver;
use App\Modules\AccessControl\StaffActor;
use App\Modules\Conversations\ConversationEvents;
use App\Modules\Conversations\ConversationPolicy;
use App\Modules\Conversations\ConversationResource;
use App\Modules\Operations\Http\Controllers\CatalogController;
use App\Support\Database\Records as R;
use App\Support\Http\ApiException;
use App\Support\Http\ApiResponse;
use App\Support\Http\ContractRequest;
use App\Support\Http\ErrorCode;
use App\Support\Security\SecretBox;
use Illuminate\Support\Facades\DB;

final class ContextController
{
    public function __invoke(ContractRequest $request): mixed
    {
        $actor = app(StaffActor::class);
        $policy = app(ConversationPolicy::class);
        $op = $request->route()->getName();
        if ($op === 'getContactDetail') {
            $id = $request->route('contact_id');
            $c = $policy->query($actor)->where('contact_id', $id)->first();
            $contact = DB::table('contacts')->where('id', $id)->first();
            if ($c === null || $contact === null) {
                throw new ApiException(ErrorCode::NotFound);
            }
            $sensitive = app(Authorizer::class)->can(Permission::ContactReadSensitive, app(ScopeResolver::class)->forConversation($c->inbox_id, $c->team_id));
            $attrs = [];
            $values = R::json($contact->attributes);
            foreach (DB::table('customer_attributes')->get() as $definition) {
                if (array_key_exists($definition->key, $values) && ($definition->visibility !== 'sensitive' || $sensitive)) {
                    $attrs[$definition->key] = $values[$definition->key];
                }
            }

            return ApiResponse::data(['id' => $contact->id, 'brand_id' => $contact->brand_id, 'name' => $contact->name ?? '訪客', 'identity_level' => DB::table('contact_identities')->where('contact_id', $id)->exists() ? 'verified' : 'anonymous', 'email' => $sensitive && $contact->email_encrypted ? app(SecretBox::class)->decrypt($contact->email_encrypted, 'contact_email:'.$id) : null, 'attributes' => (object) $attrs]);
        }
        $c = $policy->get($actor, $request->route('conversation_id'));
        if ($op === 'listConversationTags') {
            return ApiResponse::list(DB::table('tags as t')->join('conversation_tags as ct', 'ct.tag_id', '=', 't.id')->where('ct.conversation_id', $c->id)->select('t.*')->get()->map(fn ($t) => CatalogController::dto('Tag', $t))->all());
        }

        return DB::transaction(function () use ($actor, $policy, $c, $request): mixed {
            $c = $policy->get($actor, $c->id, true);
            $input = $request->payload();
            $policy->write($actor, $c, Permission::ConversationNote, false);
            $policy->version($c, $input['expected_version']);
            $ids = array_values(array_unique($input['tag_ids']));
            if (DB::table('tags')->whereIn('id', $ids)->count() !== count($ids)) {
                throw new ApiException(ErrorCode::NotFound);
            }
            DB::table('conversation_tags')->where('conversation_id', $c->id)->delete();
            foreach ($ids as $id) {
                DB::table('conversation_tags')->insert(['id' => R::id(), 'workspace_id' => $actor->workspaceId(), 'conversation_id' => $c->id, 'tag_id' => $id, 'created_at' => now()]);
            }
            // 標籤是內部資料；不送 public conversation event。
            DB::table('conversations')->where('id', $c->id)->increment('version');
            app(ConversationEvents::class)->emit($c, 'conversation.tags.updated', staffOnly: ['tag_ids' => $ids]);

            return ApiResponse::data(ConversationResource::conversation(DB::table('conversations')->where('id', $c->id)->first(), true));
        });
    }
}
