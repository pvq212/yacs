<?php

declare(strict_types=1);

namespace App\Modules\Knowledge;

use App\Modules\Ai\Contracts\EmbeddingGateway;
use App\Modules\Tasks\Tasks;
use App\Support\Database\Records as R;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

final class KnowledgeIndex
{
    /** 外部 embedding 在交易外生成，全部成功後才原子啟用該版本的 chunks。 */
    public function index(string $id, ?string $task = null, ?string $lease = null): void
    {
        $version = DB::table('knowledge_versions')->where('id', $id)->first();
        if ($version === null || ! in_array($version->state, ['draft', 'failed', 'indexing'], true)) {
            return;
        }
        $kb = DB::table('knowledge_bases as k')->join('knowledge_documents as d', 'd.knowledge_base_id', '=', 'k.id')->where('d.id', $version->document_id)->select('k.*')->first();
        $chunks = [];
        $text = $version->body_text ?? '';
        for ($offset = 0, $length = mb_strlen($text); $offset < $length; $offset += 1200) {
            $chunks[] = mb_substr($text, $offset, 1400);
        }
        if ($chunks === []) {
            throw new ApiException(ErrorCode::ValidationFailed);
        }
        $profile = $version->visibility === 'external_answerable' && $kb->active_embedding_profile_id ? DB::table('embedding_profiles')->where('id', $kb->active_embedding_profile_id)->where('status', 'active')->first() : null;
        $vectors = null;
        if ($profile !== null) {
            $vectors = app(EmbeddingGateway::class)->embed(DB::table('provider_connections')->where('id', $profile->connection_id)->first(), DB::table('ai_models')->where('id', $profile->model_id)->first(), $chunks, (int) $profile->dimensions);
        }
        DB::transaction(function () use ($version, $chunks, $profile, $vectors, $task, $lease): void {
            $locked = DB::table('knowledge_versions')->where('id', $version->id)->lockForUpdate()->first();
            if (! in_array($locked->state, ['draft', 'failed', 'indexing'], true)) {
                return;
            }
            if ($task !== null) {
                DB::table('async_tasks')->where('id', $task)->lockForUpdate()->first();
                if (! app(Tasks::class)->validLease($task, $lease)) {
                    return;
                }
            }
            DB::table('knowledge_chunks')->where('version_id', $version->id)->delete();
            foreach ($chunks as $i => $text) {
                $id = R::id();
                DB::table('knowledge_chunks')->insert(['id' => $id, 'workspace_id' => $version->workspace_id, 'version_id' => $version->id, 'chunk_index' => $i, 'text' => $text, 'search_text' => $version->title.' '.$text, 'content_hash' => hash('sha256', $text), 'token_count' => max(1, (int) ceil(mb_strlen($text) / 2)), 'created_at' => now()]);
                if ($vectors !== null) {
                    DB::table('knowledge_embeddings')->insert(['id' => R::id(), 'workspace_id' => $version->workspace_id, 'chunk_id' => $id, 'embedding_profile_id' => $profile->id, 'dimensions' => $profile->dimensions, 'embedding' => '['.implode(',', $vectors[$i]).']', 'created_at' => now()]);
                }
            }
            DB::table('knowledge_versions')->where('id', $version->id)->update(['state' => 'ready', 'failure_code' => null, 'updated_at' => now()]);
        });
    }
}
