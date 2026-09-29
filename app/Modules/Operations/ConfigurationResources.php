<?php

declare(strict_types=1);

namespace App\Modules\Operations;

use App\Support\Database\Records as R;
use Illuminate\Support\Facades\DB;

/** API 欄位與持久化欄位明確對應；secret 永遠只輸出 present。 */
final class ConfigurationResources
{
    public const TYPES = ['KnowledgeBase' => 'knowledge_bases', 'ProviderConnection' => 'provider_connections', 'AiModel' => 'ai_models', 'EmbeddingProfile' => 'embedding_profiles', 'AiProfile' => 'ai_profiles', 'WebhookEndpoint' => 'webhook_endpoints'];

    public static function dto(string $type, object $row): array
    {
        $doc = json_decode((string) file_get_contents(base_path('docs/spec/contracts/openapi.json')), true);
        $props = $doc['components']['schemas'][$type]['properties'];
        $data = [];
        foreach (array_keys($props) as $field) {
            if ($field === 'egress_policy_id' && $row->egress_policy_id === null) {
                continue;
            }
            $data[$field] = match ($field) {
                'version' => (string) $row->version,
                'locale' => $row->default_locale,
                'secret_present' => $row->secret_encrypted !== null,
                'verification_state' => $row->verification_state ?? ($row->health === 'healthy' ? 'verified' : 'unverified'),
                'max_output_tokens' => $type === 'AiProfile' ? (R::json($row->limits)['max_output_tokens'] ?? 1024) : $row->output_limit,
                'cross_provider_fallback' => R::json($row->fallback_policy)['cross_provider'] ?? false,
                'allowed_fallback_model_ids' => R::json($row->fallback_policy)['allowed_model_ids'] ?? [],
                'brand_ids' => R::json($row->brand_scope),
                'inbox_ids' => $type === 'KnowledgeBase' ? DB::table('inbox_knowledge_bases')->where('knowledge_base_id', $row->id)->pluck('inbox_id')->all() : R::json($row->inbox_scope),
                'capabilities', 'event_types', 'brand_scope', 'inbox_scope' => R::json($row->$field),
                'dimensions' => (int) $row->dimensions,
                default => $row->$field ?? null,
            };
        }

        return $data;
    }
}
