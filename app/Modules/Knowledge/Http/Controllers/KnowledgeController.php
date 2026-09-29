<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Http\Controllers;

use App\Modules\AccessControl\Authorizer;
use App\Modules\AccessControl\Permission;
use App\Modules\AccessControl\ResourceScope;
use App\Modules\AccessControl\ScopeResolver;
use App\Modules\AccessControl\StaffActor;
use App\Modules\Knowledge\DocumentParser;
use App\Modules\Knowledge\KnowledgeRetriever;
use App\Modules\Tasks\Tasks;
use App\Support\Database\Records as R;
use App\Support\Http\ApiException;
use App\Support\Http\ApiResponse;
use App\Support\Http\ContractRequest;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

final class KnowledgeController
{
    public function __invoke(ContractRequest $request): mixed
    {
        $actor = app(StaffActor::class);
        $auth = app(Authorizer::class);
        $op = $request->route()->getName();
        $input = $request->payload();
        $publish = in_array($op, ['publishKnowledgeVersion', 'unpublishDocument'], true);
        $permission = $publish ? Permission::KnowledgePublish : Permission::KnowledgeEdit;
        $auth->authorizeAnywhere($permission);
        if ($op === 'previewKnowledgeSearch') {
            $auth->authorize(Permission::KnowledgeEdit, app(ScopeResolver::class)->forInbox($input['inbox_id']));

            $external = $input['mode'] !== 'staff_assist';

            return ApiResponse::data(app(KnowledgeRetriever::class)->search($input['inbox_id'], $input['query'], $external, $external ? 'hybrid' : 'lexical'));
        }
        $documentId = $request->route('document_id');
        $versionId = $request->route('version_id');
        $version = $versionId ? DB::table('knowledge_versions')->where('id', $versionId)->first() : null;
        if ($versionId && $version === null) {
            throw new ApiException(ErrorCode::NotFound);
        }
        $documentId ??= $version?->document_id;
        $document = $documentId ? DB::table('knowledge_documents')->where('id', $documentId)->first() : null;
        if ($documentId && $document === null) {
            throw new ApiException(ErrorCode::NotFound);
        }
        $kbId = $document->knowledge_base_id ?? $input['knowledge_base_id'] ?? null;
        if ($kbId) {
            $kb = DB::table('knowledge_bases')->where('id', $kbId)->first();
            if ($kb === null) {
                throw new ApiException(ErrorCode::NotFound);
            }
            $auth->authorize($permission, ResourceScope::brand($kb->brand_id));
        }
        if ($op === 'listDocuments') {
            $allowed = DB::table('knowledge_bases')->get()->filter(fn ($k) => $auth->can(Permission::KnowledgeEdit, ResourceScope::brand($k->brand_id)))->pluck('id');
            $q = DB::table('knowledge_documents')->whereIn('knowledge_base_id', $allowed);
            if ($kbId) {
                $q->where('knowledge_base_id', $kbId);
            }

            return ApiResponse::list($q->orderByDesc('id')->limit((int) ($input['limit'] ?? 50))->get()->map(self::document(...))->all());
        }
        if ($op === 'getDocument') {
            return ApiResponse::data(self::document($document));
        }
        if ($op === 'getKnowledgeVersion') {
            return ApiResponse::data(self::version($version));
        }
        if ($op === 'listDocumentVersions') {
            return ApiResponse::list(DB::table('knowledge_versions')->where('document_id', $documentId)->orderByDesc('version_number')->get()->map(self::version(...))->all());
        }
        if ($op === 'createDocument') {
            $id = R::id();
            DB::table('knowledge_documents')->insert(['id' => $id, 'workspace_id' => $actor->workspaceId(), 'knowledge_base_id' => $kbId, 'title' => $input['title'], 'source_type' => $input['source_type'], 'external_key' => $input['external_key'] ?? null, 'created_at' => now(), 'updated_at' => now()]);

            return ApiResponse::data(self::document(DB::table('knowledge_documents')->where('id', $id)->first()), 201);
        }
        if ($op === 'createDocumentVersion') {
            $text = app(DocumentParser::class)->parse($input);

            return DB::transaction(function () use ($input, $document, $actor, $text): mixed {
                DB::table('knowledge_documents')->where('id', $document->id)->lockForUpdate()->first();
                $id = R::id();
                $number = (int) DB::table('knowledge_versions')->where('document_id', $document->id)->max('version_number') + 1;
                DB::table('knowledge_versions')->insert(['id' => $id, 'workspace_id' => $actor->workspaceId(), 'document_id' => $document->id, 'version_number' => $number, 'visibility' => $input['visibility'], 'locale' => $input['locale'], 'title' => $document->title, 'body_text' => $text, 'source_file_id' => $input['source_file_id'] ?? null, 'content_hash' => hash('sha256', $text), 'effective_from' => $input['effective_from'] ?? null, 'effective_to' => $input['effective_to'] ?? null, 'created_by' => $actor->membershipId(), 'created_at' => now(), 'updated_at' => now()]);
                foreach ($input['faq_aliases'] ?? [] as $alias) {
                    DB::table('knowledge_keywords')->insertOrIgnore(['id' => R::id(), 'workspace_id' => $actor->workspaceId(), 'version_id' => $id, 'keyword_normalized' => mb_substr(mb_strtolower($alias), 0, 200), 'kind' => 'alias']);
                }

                return ApiResponse::data(self::version(DB::table('knowledge_versions')->where('id', $id)->first()), 201);
            });
        }
        if ($op === 'indexKnowledgeVersion') {
            return ApiResponse::data(app(Tasks::class)->enqueue('knowledge.index', $version->id, 'kb', 'index:'.$version->id), 202);
        }

        return DB::transaction(function () use ($op, $version, $document, $actor, $input): mixed {
            $locked = DB::table('knowledge_documents')->where('id', $document->id)->lockForUpdate()->first();
            if ($locked->published_version_id !== $input['expected_published_version_id']) {
                throw new ApiException(ErrorCode::VersionConflict);
            }
            if ($op === 'publishKnowledgeVersion') {
                $version = DB::table('knowledge_versions')->where('id', $version->id)->lockForUpdate()->first();
                if ($version->state !== 'ready') {
                    throw new ApiException(ErrorCode::InvalidState);
                }
                $kb = DB::table('knowledge_bases')->where('id', $document->knowledge_base_id)->first();
                $total = DB::table('knowledge_chunks')->where('version_id', $version->id)->count();
                if ($total === 0) {
                    throw new ApiException(ErrorCode::InvalidState);
                }
                if ($version->visibility === 'external_answerable' && $kb->active_embedding_profile_id !== null && DB::table('knowledge_embeddings as e')->join('knowledge_chunks as c', 'c.id', '=', 'e.chunk_id')->where('c.version_id', $version->id)->where('e.embedding_profile_id', $kb->active_embedding_profile_id)->count() !== $total) {
                    throw new ApiException(ErrorCode::InvalidState);
                }
                DB::table('knowledge_versions')->where('id', $version->id)->update(['state' => 'published', 'approved_by' => $actor->membershipId(), 'published_at' => now(), 'updated_at' => now()]);
                DB::table('knowledge_documents')->where('id', $locked->id)->update(['status' => 'published', 'published_version_id' => $version->id, 'version' => (int) $locked->version + 1, 'updated_at' => now()]);
            } elseif ($op === 'unpublishDocument') {
                DB::table('knowledge_documents')->where('id', $locked->id)->update(['status' => 'unpublished', 'published_version_id' => null, 'version' => (int) $locked->version + 1, 'updated_at' => now()]);
            } else {
                throw new \LogicException('Unknown knowledge operation.');
            }
            DB::table('workspaces')->where('id', $actor->workspaceId())->increment('knowledge_generation');

            return $op === 'unpublishDocument' ? ApiResponse::noContent() : ApiResponse::data(self::document(DB::table('knowledge_documents')->where('id', $document->id)->first()));
        });
    }

    public static function document(object $d): array
    {
        return ['id' => $d->id, 'workspace_id' => $d->workspace_id, 'knowledge_base_id' => $d->knowledge_base_id, 'title' => $d->title, 'source_type' => $d->source_type, 'published_version_id' => $d->published_version_id, 'version' => (string) $d->version];
    }

    public static function version(object $v): array
    {
        return ['id' => $v->id, 'workspace_id' => $v->workspace_id, 'document_id' => $v->document_id, 'version_number' => (int) $v->version_number, 'state' => $v->state, 'visibility' => $v->visibility, 'locale' => $v->locale, 'body_text' => $v->body_text ?? '', 'published_at' => R::date($v->published_at)];
    }
}
