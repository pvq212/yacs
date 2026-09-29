<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Integrations\IntegrationActor;
use App\Support\Database\Records as R;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use App\Support\Http\Principal;
use App\Support\Tenancy\TenantDatabase;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AuthenticateIntegration
{
    public function handle(Request $request, Closure $next): mixed
    {
        $token = (string) $request->bearerToken();
        $tenant = app(TenantDatabase::class);
        $client = strlen($token) >= 32 ? $tenant->asSystem(fn () => DB::table('api_clients')->where('token_hash', hash('sha256', $token))->where('status', 'active')->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first()) : null;
        if ($client === null || (R::json($client->allowed_ips) !== [] && ! in_array($request->ip(), R::json($client->allowed_ips), true))) {
            throw new ApiException(ErrorCode::Unauthenticated);
        }
        $tenant->enterWorkspace($client->workspace_id);
        $actor = new IntegrationActor($client);
        app()->instance(IntegrationActor::class, $actor);
        app()->instance(Principal::class, $actor);

        return $next($request);
    }
}
