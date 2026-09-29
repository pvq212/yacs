<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Http\Controllers;

use App\Modules\AccessControl\StaffActor;
use App\Support\Http\ApiResponse;
use App\Support\Http\ContractRequest;
use Illuminate\Support\Facades\DB;

final class PresenceController
{
    public function __invoke(ContractRequest $request): mixed
    {
        $actor = app(StaffActor::class);
        $input = $request->payload();
        DB::table('agent_capacity')->where('membership_id', $actor->membershipId())->update(['presence_status' => $input['state'], 'last_heartbeat_at' => now(), 'offline_since' => $input['state'] === 'offline' ? now() : null, 'updated_at' => now()]);

        return ApiResponse::noContent();
    }
}
