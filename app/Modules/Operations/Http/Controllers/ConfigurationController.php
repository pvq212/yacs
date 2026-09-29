<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Controllers;

use App\Modules\AccessControl\Authorizer;
use App\Modules\AccessControl\Permission;
use App\Modules\AccessControl\ResourceScope;
use App\Modules\AccessControl\StaffActor;
use App\Modules\Audit\AuditActor;
use App\Modules\Audit\AuditLogger;
use App\Modules\Operations\ConfigurationResources as DTO;
use App\Support\Database\Records as R;
use App\Support\Http\ApiException;
use App\Support\Http\ApiResponse;
use App\Support\Http\ContractRequest;
use App\Support\Http\ErrorCode;
use App\Support\Security\Egress;
use App\Support\Security\SecretBox;
use Illuminate\Support\Facades\DB;

final class ConfigurationController
{
    public function __invoke(ContractRequest $request): mixed
    {
        $op = $request->route()->getName();
        $type = null;
        foreach (array_keys(DTO::TYPES) as $candidate) {
            if (str_ends_with($op, $candidate)) {
                $type = $candidate;
                break;
            }
        }
        if ($type === null) {
            throw new \LogicException('Unknown configuration resource.');
        }
        $table = DTO::TYPES[$type];
        $actor = app(StaffActor::class);
        $auth = app(Authorizer::class);
        $input = $request->payload();
        $permission = $type === 'KnowledgeBase' ? Permission::KnowledgeEdit : ($type === 'WebhookEndpoint' ? Permission::IntegrationManage : Permission::AiManage);
        $auth->authorizeAnywhere($permission);
        $id = null;
        foreach ($request->route()->parameters() as $key => $value) {
            if ($key !== 'workspace_id') {
                $id = $value;
            }
        }
        $row = $id ? DB::table($table)->where('id', $id)->first() : null;
        if ($id && $row === null) {
            throw new ApiException(ErrorCode::NotFound);
        }
        if ($type === 'KnowledgeBase' && $row) {
            $auth->authorize($permission, ResourceScope::brand($row->brand_id));
        } elseif ($type !== 'KnowledgeBase') {
            $auth->authorize($permission);
        }
        if (str_starts_with($op, 'list')) {
            $rows = DB::table($table)->orderByDesc('id')->get()->filter(fn ($r) => $type !== 'KnowledgeBase' || $auth->can($permission, ResourceScope::brand($r->brand_id)));

            return ApiResponse::list($rows->map(fn ($r) => DTO::dto($type, $r))->values()->all());
        }
        if (str_starts_with($op, 'get')) {
            return ApiResponse::data(DTO::dto($type, $row));
        }

        return DB::transaction(function () use ($type, $table, $row, $input, $actor, $auth, $permission): mixed {
            $id = $row->id ?? R::id();
            $create = $row === null;
            $values = $input;
            unset($values['expected_version']);
            if ($type === 'KnowledgeBase') {
                unset($values['inbox_ids']);
            }
            if (! $create) {
                $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
                if ((string) $row->version !== $input['expected_version']) {
                    throw new ApiException(ErrorCode::VersionConflict);
                }
                if ($type === 'EmbeddingProfile' && array_diff(array_keys($input), ['expected_version', 'status']) !== []) {
                    throw new ApiException(ErrorCode::InvalidState, null, ['reason' => 'immutable_profile_create_new']);
                }
                $values['version'] = (int) $row->version + 1;
            } else {
                $values += ['id' => $id, 'workspace_id' => $actor->workspaceId(), 'created_at' => now()];
            }
            $values['updated_at'] = now();
            if ($type === 'KnowledgeBase') {
                $brand = $input['brand_id'] ?? $row->brand_id;
                $auth->authorize($permission, ResourceScope::brand($brand));
                if (isset($values['locale'])) {
                    $values['default_locale'] = $values['locale'];
                    unset($values['locale']);
                }
                foreach ($input['inbox_ids'] ?? [] as $inbox) {
                    if (! DB::table('inboxes')->where('id', $inbox)->where('brand_id', $brand)->exists()) {
                        throw new ApiException(ErrorCode::NotFound);
                    }
                }
            }
            foreach (['connection_id' => 'provider_connections', 'chat_model_id' => 'ai_models', 'model_id' => 'ai_models', 'embedding_profile_id' => 'embedding_profiles', 'active_embedding_profile_id' => 'embedding_profiles', 'rerank_model_id' => 'ai_models'] as $field => $parent) {
                if (isset($values[$field]) && ! DB::table($parent)->where('id', $values[$field])->exists()) {
                    throw new ApiException(ErrorCode::NotFound);
                }
            }
            if ($type === 'ProviderConnection') {
                if (isset($values['base_url'])) {
                    app(Egress::class)->request($values['base_url']);
                }
                if (array_key_exists('api_key', $values)) {
                    $values['secret_encrypted'] = app(SecretBox::class)->encrypt($values['api_key'], 'provider:'.$id);
                    unset($values['api_key']);
                }
                if (! $create) {
                    $values['configuration_version'] = (int) $row->configuration_version + 1;
                }
            }
            if ($type === 'AiModel' && ! $create) {
                $values['verification_state'] = 'unverified';
                $values['verified_at'] = null;
            }
            if ($type === 'AiModel' && array_key_exists('max_output_tokens', $values)) {
                $values['output_limit'] = $values['max_output_tokens'];
                unset($values['max_output_tokens']);
            }
            if ($type === 'EmbeddingProfile' && $create) {
                $values['name'] = 'embedding-'.$id;
                $model = DB::table('ai_models')->where('id', $values['model_id'])->first();
                if ($model->verification_state !== 'verified' || $model->connection_id !== $values['connection_id'] || ! (R::json($model->capabilities)['embeddings'] ?? false)) {
                    throw new ApiException(ErrorCode::CapabilityUnsupported);
                }
            }
            if ($type === 'AiProfile') {
                if (isset($values['max_output_tokens'])) {
                    $values['limits'] = R::encode(array_merge(R::json($row->limits ?? null), ['max_output_tokens' => $values['max_output_tokens']]));
                    unset($values['max_output_tokens']);
                }
                $previous = R::json($row->fallback_policy ?? null);
                $fallback = ['cross_provider' => $values['cross_provider_fallback'] ?? $previous['cross_provider'] ?? false, 'allowed_model_ids' => $values['allowed_fallback_model_ids'] ?? $previous['allowed_model_ids'] ?? []];
                // 初版跨供應商備援不外送資料；要啟用必須另提供同意與目的地政策。
                if ($fallback['cross_provider']) {
                    throw new ApiException(ErrorCode::CapabilityUnsupported, null, ['reason' => 'cross_provider_policy_required']);
                }
                foreach ($fallback['allowed_model_ids'] as $fallbackId) {
                    $primary = DB::table('ai_models')->where('id', $values['chat_model_id'] ?? $row->chat_model_id)->first();
                    $backup = DB::table('ai_models')->where('id', $fallbackId)->where('status', 'active')->where('verification_state', 'verified')->first();
                    if ($backup === null || $primary === null || $backup->connection_id !== $primary->connection_id || ! (R::json($backup->capabilities)['text'] ?? false)) {
                        throw new ApiException(ErrorCode::CapabilityUnsupported);
                    }
                }
                $values['fallback_policy'] = R::encode($fallback);
                unset($values['cross_provider_fallback'], $values['allowed_fallback_model_ids']);
                if (! $create) {
                    $values['configuration_version'] = (int) $row->configuration_version + 1;
                }
            }
            if ($type === 'WebhookEndpoint') {
                foreach (['brand_ids' => 'brand_scope', 'inbox_ids' => 'inbox_scope'] as $field => $target) {
                    if (isset($values[$field])) {
                        $values[$target] = $values[$field];
                        unset($values[$field]);
                    }
                }
                if ($create) {
                    $values['name'] = 'endpoint-'.$id;
                    $values['key_id'] = 'k1';
                    $values['secret_encrypted'] = app(SecretBox::class)->encrypt($values['secret'] ?? bin2hex(random_bytes(32)), 'webhook:'.$id);
                }
                if (isset($values['url'])) {
                    app(Egress::class)->request($values['url'], 10);
                }
                if (isset($values['secret'])) {
                    $values['secret_encrypted'] = app(SecretBox::class)->encrypt($values['secret'], 'webhook:'.$id);
                    unset($values['secret']);
                }
            }
            foreach (['capabilities', 'event_types', 'brand_scope', 'inbox_scope', 'headers_safe'] as $json) {
                if (isset($values[$json])) {
                    $values[$json] = R::encode($values[$json]);
                }
            }
            if ($create) {
                DB::table($table)->insert($values);
            } else {
                DB::table($table)->where('id', $id)->update($values);
            }
            if ($type === 'ProviderConnection' && ! $create) {
                DB::table('ai_models')->where('connection_id', $id)->update(['verification_state' => 'unverified', 'verified_at' => null]);
            }
            if ($type === 'KnowledgeBase' && array_key_exists('inbox_ids', $input)) {
                DB::table('inbox_knowledge_bases')->where('knowledge_base_id', $id)->delete();
                foreach ($input['inbox_ids'] as $inbox) {
                    DB::table('inbox_knowledge_bases')->insert(['id' => R::id(), 'workspace_id' => $actor->workspaceId(), 'inbox_id' => $inbox, 'knowledge_base_id' => $id]);
                }
                DB::table('workspaces')->where('id', $actor->workspaceId())->increment('knowledge_generation');
            }
            $after = DTO::dto($type, DB::table($table)->where('id', $id)->first());
            app(AuditLogger::class)->record($actor->workspaceId(), AuditActor::staff($actor), $table.'.'.($create ? 'created' : 'updated'), $table, $id, $row ? DTO::dto($type, $row) : null, $after);

            return ApiResponse::data($after, $create ? 201 : 200);
        });
    }
}
