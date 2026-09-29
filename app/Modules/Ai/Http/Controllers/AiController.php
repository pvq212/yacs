<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Controllers;

use App\Modules\AccessControl\Authorizer;
use App\Modules\AccessControl\Permission;
use App\Modules\AccessControl\StaffActor;
use App\Modules\Ai\Adapters\NativeHttpAdapter;
use App\Modules\Ai\Autopilot;
use App\Modules\Ai\Contracts\ChatGateway;
use App\Modules\Ai\DTOs\ChatRequest;
use App\Modules\Conversations\ConversationPolicy;
use App\Support\Http\ApiException;
use App\Support\Http\ApiResponse;
use App\Support\Http\ContractRequest;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

final class AiController
{
    public function __invoke(ContractRequest $request): mixed
    {
        $actor = app(StaffActor::class);
        $op = $request->route()->getName();
        $input = $request->payload();
        if ($op === 'probeProvider') {
            app(Authorizer::class)->authorize(Permission::AiManage);
            $connection = DB::table('provider_connections')->where('id', $request->route('provider_connection_id'))->where('status', 'active')->first();
            $model = DB::table('ai_models')->where('id', $input['model_id'])->first();
            if ($connection === null || $model === null || $model->connection_id !== $connection->id || $model->status !== 'active') {
                throw new ApiException(ErrorCode::NotFound);
            }
            if (count($input['capabilities']) !== 1 || ! in_array($input['capabilities'][0], ['text', 'embeddings'], true)) {
                throw new ApiException(ErrorCode::CapabilityUnsupported);
            }
            $declared = json_decode($model->capabilities, true);
            if (! ($declared[$input['capabilities'][0]] ?? false)) {
                throw new ApiException(ErrorCode::CapabilityUnsupported);
            }
            if ($input['capabilities'][0] === 'embeddings') {
                app(NativeHttpAdapter::class)->embed($connection, $model, ['測試']);
            } else {
                $result = app(ChatGateway::class)->generate(new ChatRequest($connection, $model, 'Reply OK.', [['role' => 'user', 'content' => 'OK']], 256));
                if ($result->finishReason !== 'stop' || trim($result->text) === '') {
                    throw new ApiException(ErrorCode::InvalidState);
                }
            }
            DB::transaction(function () use ($model, $connection): void {
                $currentConnection = DB::table('provider_connections')->where('id', $connection->id)->lockForUpdate()->first();
                $currentModel = DB::table('ai_models')->where('id', $model->id)->lockForUpdate()->first();
                if ($currentConnection === null || $currentModel === null || $currentConnection->status !== 'active' || $currentModel->status !== 'active' || (string) $currentConnection->configuration_version !== (string) $connection->configuration_version || (string) $currentModel->version !== (string) $model->version) {
                    throw new ApiException(ErrorCode::VersionConflict);
                }
                DB::table('ai_models')->where('id', $model->id)->update(['verification_state' => 'verified', 'verified_at' => now()]);
                DB::table('provider_connections')->where('id', $connection->id)->update(['health' => 'healthy', 'last_probe_at' => now()]);
            });

            return ApiResponse::data(['verified' => true, 'capability' => $input['capabilities'][0]]);
        }
        if ($op === 'getAiRun') {
            $run = DB::table('ai_runs')->where('id', $request->route('ai_run_id'))->first();
            if ($run === null) {
                throw new ApiException(ErrorCode::NotFound);
            }
            app(ConversationPolicy::class)->get($actor, $run->conversation_id);

            return ApiResponse::data(Autopilot::dto($run));
        }
        $c = app(ConversationPolicy::class)->get($actor, $request->route('conversation_id'));
        app(ConversationPolicy::class)->write($actor, $c, Permission::ConversationReply);
        if (($input['kind'] ?? 'draft') === 'summary') {
            throw new ApiException(ErrorCode::CapabilityUnsupported);
        }
        $run = app(Autopilot::class)->enqueue($c, 'assist_draft', $actor->membershipId(), $request->header('Idempotency-Key'));

        return ApiResponse::data(Autopilot::dto($run), 202);
    }
}
