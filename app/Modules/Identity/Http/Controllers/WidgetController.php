<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Visitor\VisitorActor;
use App\Modules\Identity\Visitor\VisitorSessions;
use App\Modules\Knowledge\KnowledgeRetriever;
use App\Modules\Workspaces\Support\BrandTheme;
use App\Modules\Workspaces\Support\OriginNormalizer;
use App\Support\Database\Records as R;
use App\Support\Http\ApiException;
use App\Support\Http\ApiResponse;
use App\Support\Http\ContractRequest;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

final class WidgetController
{
    public function __invoke(ContractRequest $request): mixed
    {
        $op = $request->route()->getName();
        $input = $request->payload();
        $sessions = app(VisitorSessions::class);
        if ($op === 'bootstrapWidget') {
            $inbox = $request->attributes->get('widget_inbox');
            $origin = OriginNormalizer::normalize($input['parent_origin']);
            $actual = $request->headers->get('Origin');
            if ($origin === null || ! DB::table('inbox_origins')->where('inbox_id', $inbox->id)->where('origin', $origin)->exists() || ($actual !== null && $actual !== $origin && $actual !== OriginNormalizer::normalize((string) config('app.url')))) {
                throw new ApiException(ErrorCode::Forbidden);
            }
            $brand = DB::table('brands')->where('id', $inbox->brand_id)->first();

            return ApiResponse::data(['session' => $sessions->anonymous($inbox, ['parent_origin' => $origin]), 'theme' => BrandTheme::withDefaults(R::json($brand->settings)['theme'] ?? [], $brand->name), 'inbox_status' => DB::table('agent_capacity')->where('presence_status', 'available')->where('last_heartbeat_at', '>', now()->subSeconds(90))->exists() ? 'online' : 'offline', 'websocket' => ['host' => (string) config('broadcasting.connections.reverb.options.host', 'localhost'), 'port' => (int) config('broadcasting.connections.reverb.options.port', 8080), 'tls' => config('broadcasting.connections.reverb.options.scheme') === 'https', 'app_key' => (string) config('broadcasting.connections.reverb.key', '')]], 201);
        }
        if ($op === 'identifyVisitor') {
            return ApiResponse::data($sessions->identify($request->attributes->get('widget_inbox'), $input['assertion'], $request->bearerToken()));
        }
        if ($op === 'refreshVisitorToken') {
            return ApiResponse::data($sessions->refresh($input['refresh_token']));
        }
        $actor = app(VisitorActor::class);
        if ($op === 'logoutVisitor') {
            $sessions->revoke($actor->session->id, 'logout');

            return ApiResponse::noContent();
        }
        if ($op === 'updateWidgetContext') {
            DB::table('visitor_sessions')->where('id', $actor->session->id)->update(['context' => R::encode($input), 'updated_at' => now()]);

            return ApiResponse::noContent();
        }
        if ($op === 'updateVisitorAttributes') {
            $attributes = $input['attributes'] ?? $input;
            foreach ($attributes as $key => $value) {
                $definition = DB::table('customer_attributes')->where('key', $key)->where('editable_by_visitor', true)->where('visibility', 'staff')->first();
                $valid = $definition !== null && match ($definition->type) {
                    'string' => is_string($value) && mb_strlen($value) <= 500, 'boolean' => is_bool($value), 'number' => is_int($value) || is_float($value), 'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value), default => false
                };
                if (! $valid) {
                    throw new ApiException(ErrorCode::ValidationFailed, null, ['fields' => [$key => ['not_visitor_editable_or_invalid']]]);
                }
            }
            DB::transaction(function () use ($actor, $attributes): void {
                $contact = DB::table('contacts')->where('id', $actor->contactId())->lockForUpdate()->first();
                DB::table('contacts')->where('id', $contact->id)->update(['attributes' => R::encode(array_merge(R::json($contact->attributes), $attributes)), 'updated_at' => now()]);
            });

            return ApiResponse::noContent();
        }
        if ($op === 'listWidgetFaqs') {
            return ApiResponse::list(app(KnowledgeRetriever::class)->faqs($actor->inboxId(), (int) ($input['limit'] ?? 50)));
        }
        throw new \LogicException('Unknown widget operation: '.$op);
    }
}
