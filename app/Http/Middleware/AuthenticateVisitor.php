<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Identity\Visitor\VisitorActor;
use App\Modules\Identity\Visitor\VisitorSessions;
use App\Support\Http\Principal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateVisitor
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = new VisitorActor(app(VisitorSessions::class)->authenticate((string) $request->bearerToken()));
        app()->instance(VisitorActor::class, $actor);
        app()->instance(Principal::class, $actor);

        return $next($request);
    }
}
