<?php

declare(strict_types=1);

namespace App\Modules\Files\Http\Controllers;

use App\Modules\AccessControl\StaffActor;
use App\Modules\Files\Files;
use App\Modules\Identity\Visitor\VisitorActor;
use App\Support\Http\ApiException;
use App\Support\Http\ApiResponse;
use App\Support\Http\ContractRequest;
use App\Support\Http\ErrorCode;
use App\Support\Http\Principal;
use Illuminate\Support\Facades\URL;

final class FileController
{
    public function __invoke(ContractRequest $request): mixed
    {
        $op = $request->route()->getName();
        $files = app(Files::class);
        $actor = app(Principal::class);
        if (! ($actor instanceof VisitorActor || $actor instanceof StaffActor)) {
            throw new ApiException(ErrorCode::Forbidden);
        }
        if (str_starts_with($op, 'initiate')) {
            return ApiResponse::data($files->ticket($actor, $request->payload()), 201);
        }
        $f = $files->authorize($actor, $request->route('file_id'), str_starts_with($op, 'complete'));
        if (str_starts_with($op, 'complete')) {
            $f = $files->complete($f);
        }
        if (str_starts_with($op, 'download')) {
            if ($f->scan_state !== 'clean') {
                throw new ApiException(ErrorCode::InvalidState);
            }
            $expiry = now()->addSeconds(60);

            return ApiResponse::data(['url' => URL::temporarySignedRoute('downloadBlob', $expiry, ['file' => $f->id, 'workspace' => $actor->workspaceId(), 'principal' => $actor instanceof VisitorActor ? 'visitor' : 'staff', 'session' => $actor instanceof VisitorActor ? $actor->session->id : $actor->sessionId(), 'membership' => $actor instanceof VisitorActor ? null : $actor->membershipId()]), 'expires_at' => $expiry->toISOString()]);
        }

        return ApiResponse::data(Files::dto($f), str_starts_with($op, 'complete') ? 202 : 200);
    }
}
