<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Identity\Visitor\PublicActor;
use App\Modules\Identity\Visitor\VisitorSessions;
use App\Support\Http\Principal;
use Closure;
use Illuminate\Http\Request;

final class ResolveWidgetInbox
{
    public function handle(Request $request, Closure $next): mixed
    {
        $inbox = app(VisitorSessions::class)->inbox((string) $request->json('inbox_key'));
        $request->attributes->set('widget_inbox', $inbox);
        $scope = $request->json('assertion') ?: $request->ip().'|'.$inbox->id;
        app()->instance(Principal::class, new PublicActor($inbox->workspace_id, 'bootstrap:'.hash('sha256', (string) $scope)));

        return $next($request);
    }
}
