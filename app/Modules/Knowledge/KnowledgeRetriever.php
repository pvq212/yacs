<?php

declare(strict_types=1);

namespace App\Modules\Knowledge;

use App\Modules\Ai\Contracts\EmbeddingGateway;
use App\Support\Database\Records as R;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class KnowledgeRetriever
{
    public function query(string $inbox, bool $external = true): Builder
    {
        $q = DB::table('knowledge_chunks as c')->join('knowledge_versions as v', 'v.id', '=', 'c.version_id')->join('knowledge_documents as d', 'd.published_version_id', '=', 'v.id')->join('knowledge_bases as k', 'k.id', '=', 'd.knowledge_base_id')->join('inbox_knowledge_bases as link', 'link.knowledge_base_id', '=', 'k.id')->join('inboxes as i', 'i.id', '=', 'link.inbox_id')->where('link.inbox_id', $inbox)->whereColumn('i.brand_id', 'k.brand_id')->where('d.status', 'published')->where('v.state', 'published')->where('k.status', 'active')->where(fn ($q) => $q->whereNull('v.effective_from')->orWhere('v.effective_from', '<=', now()))->where(fn ($q) => $q->whereNull('v.effective_to')->orWhere('v.effective_to', '>', now()))->whereRaw("(v.inbox_scope = '[]'::jsonb OR v.inbox_scope @> ?::jsonb)", [R::encode([$inbox])]);

        return $external ? $q->where('v.visibility', 'external_answerable') : $q;
    }

    public function search(string $inbox, string $question, bool $external = true, string $mode = 'hybrid'): array
    {
        $q = $this->query($inbox, $external);
        $select = ['c.id', 'c.version_id', 'c.text', 'v.title', 'v.public_url', 'k.active_embedding_profile_id'];
        $keywords = array_values(array_filter(preg_split('/[\s\p{P}]+/u', trim($question)) ?: []));
        $lexical = (clone $q)->where(function ($q) use ($question, $keywords): void {
            $q->whereRaw('c.search_text &@~ ?', [$question]);
            foreach (array_slice($keywords, 0, 10) as $word) {
                $q->orWhere('c.search_text', 'ilike', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $word).'%');
            }
            $q->orWhereExists(fn ($alias) => $alias->selectRaw('1')->from('knowledge_keywords as alias')->whereColumn('alias.version_id', 'v.id')->where('alias.keyword_normalized', '<>', '')->whereRaw('position(alias.keyword_normalized in ?) > 0', [mb_strtolower($question)]));
        })->limit(20)->get($select);
        $scores = [];
        $rows = [];
        $degraded = false;
        if ($mode !== 'vector') {
            foreach ($lexical as $rank => $row) {
                $scores[$row->id] = 1 / (61 + $rank);
                $rows[$row->id] = $row;
            }
        }
        $profileIds = (clone $q)->whereNotNull('k.active_embedding_profile_id')->distinct()->pluck('k.active_embedding_profile_id');
        if ($mode !== 'lexical') {
            foreach ($profileIds as $profileId) {
                try {
                    $p = DB::table('embedding_profiles')->where('id', $profileId)->where('status', 'active')->first();
                    if ($p === null) {
                        continue;
                    }
                    $vectors = app(EmbeddingGateway::class)->embed(DB::table('provider_connections')->where('id', $p->connection_id)->where('status', 'active')->first(), DB::table('ai_models')->where('id', $p->model_id)->where('status', 'active')->first(), [$question], (int) $p->dimensions);
                    $vector = '['.implode(',', $vectors[0]).']';
                    $found = (clone $q)->join('knowledge_embeddings as e', 'e.chunk_id', '=', 'c.id')->where('e.embedding_profile_id', $profileId)->where('k.active_embedding_profile_id', $profileId)->orderByRaw('e.embedding <=> ?::vector', [$vector])->limit(20)->get($select);
                    foreach ($found as $rank => $row) {
                        $scores[$row->id] = ($scores[$row->id] ?? 0) + 1 / (61 + $rank);
                        $rows[$row->id] = $row;
                    }
                } catch (\Throwable) {
                    $degraded = true;
                }
            }
        }
        arsort($scores);
        $sources = [];
        foreach (array_slice(array_keys($scores), 0, 5) as $index => $id) {
            $r = $rows[$id];
            $sources[] = ['label' => 'S'.($index + 1), 'chunk_id' => $r->id, 'version_id' => $r->version_id, 'title' => $r->title, 'text' => $r->text, 'score' => $scores[$id], 'public_url' => $r->public_url];
        }

        return ['sources' => $sources, 'profile_id' => $profileIds->count() === 1 ? $profileIds[0] : null, 'degraded' => $degraded || ($mode === 'vector' && $profileIds->isEmpty())];
    }

    public function faqs(string $inbox, int $limit): array
    {
        return $this->query($inbox)->where('d.source_type', 'faq')->where('c.chunk_index', 0)->limit($limit)->get(['d.id', 'v.title', 'v.body_text'])->map(fn ($r) => ['id' => $r->id, 'question' => $r->title, 'answer' => $r->body_text])->all();
    }
}
