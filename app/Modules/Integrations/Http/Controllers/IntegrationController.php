<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Controllers;

use App\Modules\Conversations\ConversationPolicy;
use App\Modules\Conversations\ConversationResource as DTO;
use App\Modules\Conversations\Conversations;
use App\Modules\Conversations\Messages;
use App\Modules\Integrations\Contacts;
use App\Modules\Integrations\IntegrationActor;
use App\Support\Http\ApiException;
use App\Support\Http\ApiResponse;
use App\Support\Http\ContractRequest;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

final class IntegrationController
{
    public function __invoke(ContractRequest $request): mixed
    {
        $actor = app(IntegrationActor::class);
        $input = $request->payload();
        $op = $request->route()->getName();
        if ($op === 'upsertIntegrationContact') {
            $actor->allow('contacts.upsert', $input['brand_id']);
            // Token 固定自己的 issuer namespace；不能冒用宿主會員簽發者。
            if ($input['issuer'] !== $actor->client->contact_issuer) {
                throw new ApiException(ErrorCode::Forbidden);
            }

            return ApiResponse::data(Contacts::dto(app(Contacts::class)->upsert($actor->workspaceId(), $input['brand_id'], $input['issuer'], $input['subject'], $input)));
        }
        if ($op === 'createIntegrationConversation') {
            $inbox = DB::table('inboxes')->where('id', $input['inbox_id'])->first();
            if ($inbox === null) {
                throw new ApiException(ErrorCode::NotFound);
            }
            $actor->allow('conversations.create', $inbox->brand_id, $inbox->id);
            if (! DB::table('contact_identities')->where('contact_id', $input['contact_id'])->where('issuer', $actor->client->contact_issuer)->exists()) {
                throw new ApiException(ErrorCode::Forbidden);
            }

            return ApiResponse::data(DTO::conversation(app(Conversations::class)->create($actor, $input, 'api')), 201);
        }
        $c = app(ConversationPolicy::class)->get($actor, $request->route('conversation_id'));
        $actor->allow(match ($op) {
            'getIntegrationConversation' => 'conversations.read', 'integrationHandoff' => 'conversations.handoff', default => 'messages.write'
        }, $c->brand_id, $c->inbox_id);
        if ($op === 'getIntegrationConversation') {
            return ApiResponse::data(DTO::conversation($c));
        }
        if ($op === 'integrationHandoff') {
            return DB::transaction(fn () => ApiResponse::data(DTO::conversation(app(Conversations::class)->handoff(app(ConversationPolicy::class)->get($actor, $c->id, true)))));
        }
        // 可重試的 UUID 由外部 message id 與 token namespace 決定，仍保存原 external id。
        $hash = hash('sha256', $actor->client->id.'|'.$input['external_message_id']);
        $uuid = substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-4'.substr($hash, 13, 3).'-a'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
        $message = app(Messages::class)->send($actor, $c->id, ['client_message_id' => $uuid, 'body_text' => $input['body_text'], 'attachment_ids' => $input['attachment_ids'] ?? []]);

        return ApiResponse::data(DTO::message($message), 201);
    }
}
