<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Http\Controllers;

use App\Modules\AccessControl\StaffActor;
use App\Modules\Conversations\ConversationPolicy;
use App\Modules\Identity\Visitor\VisitorActor;
use App\Support\Http\ApiException;
use App\Support\Http\ApiResponse;
use App\Support\Http\ContractRequest;
use App\Support\Http\ErrorCode;
use App\Support\Http\Principal;

final class RealtimeController
{
    public function __invoke(ContractRequest $request): mixed
    {
        $input = $request->payload();
        $actor = app(Principal::class);
        if (! ($actor instanceof VisitorActor || $actor instanceof StaffActor)) {
            throw new ApiException(ErrorCode::Forbidden);
        }
        $type = $actor instanceof VisitorActor ? 'visitor' : 'staff';
        $session = $actor instanceof VisitorActor ? $actor->session->id : $actor->sessionId();
        $prefix = 'private-'.$type.'.session.'.$session.'.conversation.';
        if (! str_starts_with($input['channel_name'], $prefix) || ! preg_match('/^[0-9]+\.[0-9]+$/', $input['socket_id'])) {
            throw new ApiException(ErrorCode::Forbidden);
        }
        app(ConversationPolicy::class)->get($actor, substr($input['channel_name'], strlen($prefix)));
        $key = (string) config('broadcasting.connections.reverb.key');
        $secret = (string) config('broadcasting.connections.reverb.secret');
        if ($key === '' || $secret === '') {
            throw new ApiException(ErrorCode::TemporarilyUnavailable);
        }

        return ApiResponse::data(['auth' => $key.':'.hash_hmac('sha256', $input['socket_id'].':'.$input['channel_name'], $secret)]);
    }
}
