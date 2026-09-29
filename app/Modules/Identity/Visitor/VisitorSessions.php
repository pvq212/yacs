<?php

declare(strict_types=1);

namespace App\Modules\Identity\Visitor;

use App\Support\Database\Records as R;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use App\Support\Security\SecretBox;
use App\Support\Tenancy\TenantDatabase;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\DB;

/** 訪客 access token 與旋轉 refresh family 的權威資料在 PostgreSQL。 */
final class VisitorSessions
{
    public function __construct(private readonly TenantDatabase $tenant, private readonly SecretBox $secrets) {}

    public function inbox(string $key): object
    {
        $inbox = $this->tenant->asSystem(fn () => DB::table('inboxes as i')->join('brands as b', 'b.id', '=', 'i.brand_id')->join('workspaces as w', 'w.id', '=', 'i.workspace_id')->where('i.public_key', $key)->where('i.status', 'active')->where('b.status', 'active')->where('w.status', 'active')->select('i.*')->first());
        if ($inbox === null) {
            throw new ApiException(ErrorCode::NotFound);
        }
        $this->tenant->enterWorkspace($inbox->workspace_id);

        return $inbox;
    }

    public function authenticate(string $token): object
    {
        if (strlen($token) < 32 || strlen($token) > 256) {
            throw new ApiException(ErrorCode::Unauthenticated);
        }
        $session = $this->tenant->asSystem(fn () => DB::table('visitor_sessions')->where('access_token_hash', hash('sha256', $token))->whereNull('revoked_at')->where('access_expires_at', '>', now())->first());
        if ($session === null) {
            throw new ApiException(ErrorCode::Unauthenticated);
        }
        $this->tenant->enterWorkspace($session->workspace_id);
        $this->assertActive($session);

        return $session;
    }

    public function assertActive(object $session): void
    {
        if ($session->revoked_at !== null || ! DB::table('contacts')->where('id', $session->contact_id)->where('status', 'active')->exists()) {
            throw new ApiException(ErrorCode::Unauthenticated);
        }
        $this->inbox((string) DB::table('inboxes')->where('id', $session->inbox_id)->value('public_key'));
        if ($session->identity_issuer_id !== null && ! DB::table('identity_issuers')->where('id', $session->identity_issuer_id)->where('status', 'active')->exists()) {
            throw new ApiException(ErrorCode::Unauthenticated);
        }
    }

    public function create(object $inbox, string $contactId, string $level, ?string $issuerId = null, array $context = []): array
    {
        $id = R::id();
        $access = bin2hex(random_bytes(32));
        $refresh = bin2hex(random_bytes(32));
        $expires = now()->addSeconds((int) config('yacs.identity.access_token_ttl_seconds'));
        $refreshExpires = now()->addSeconds((int) config('yacs.identity.refresh_idle_ttl_seconds'));
        DB::table('visitor_sessions')->insert(['id' => $id, 'workspace_id' => $inbox->workspace_id, 'brand_id' => $inbox->brand_id, 'inbox_id' => $inbox->id, 'contact_id' => $contactId, 'identity_level' => $level, 'identity_issuer_id' => $issuerId, 'access_token_hash' => hash('sha256', $access), 'access_expires_at' => $expires, 'refresh_expires_at' => $refreshExpires, 'context' => R::encode($context), 'created_at' => now(), 'updated_at' => now()]);
        $this->refreshRow($inbox->workspace_id, $id, R::id(), $refresh, $refreshExpires);

        return $this->tokens($id, $access, $refresh, $expires->toISOString(), $level);
    }

    public function anonymous(object $inbox, array $context): array
    {
        return DB::transaction(function () use ($inbox, $context): array {
            $contact = R::id();
            DB::table('contacts')->insert(['id' => $contact, 'workspace_id' => $inbox->workspace_id, 'brand_id' => $inbox->brand_id, 'name' => '訪客', 'created_at' => now(), 'updated_at' => now()]);

            return $this->create($inbox, $contact, 'anonymous', context: $context);
        });
    }

    public function identify(object $inbox, string $assertion, ?string $previousToken): array
    {
        try {
            $parts = explode('.', $assertion);
            if (count($parts) !== 3) {
                throw new \RuntimeException;
            }
            $header = JWT::jsonDecode(JWT::urlsafeB64Decode($parts[0]));
            $untrusted = JWT::jsonDecode(JWT::urlsafeB64Decode($parts[1]));
            $issuer = DB::table('identity_issuers')->where('brand_id', $inbox->brand_id)->where('issuer', (string) ($untrusted->iss ?? ''))->where('key_id', (string) ($header->kid ?? ''))->where('status', 'active')->first();
            if ($issuer === null) {
                throw new \RuntimeException;
            }
            $key = $this->secrets->decrypt($issuer->secret_encrypted, 'identity_issuer:'.$issuer->id);
            $claims = JWT::decode($assertion, new Key($key, 'HS256'));
            $scope = R::json($issuer->inbox_scope);
            if (($claims->aud ?? null) !== 'yacs:visitor' || ($claims->workspace_id ?? null) !== $inbox->workspace_id || ($claims->brand_id ?? null) !== $inbox->brand_id || ($claims->inbox_key ?? null) !== $inbox->public_key || ! is_string($claims->sub ?? null) || $claims->sub === '' || strlen($claims->sub) > 200 || ! is_string($claims->jti ?? null) || strlen($claims->jti) < 16 || ! is_int($claims->iat ?? null) || ! is_int($claims->exp ?? null) || $claims->exp - $claims->iat > 60 || $claims->iat > time() + 30 || $claims->iat < time() - 90 || ($scope !== [] && ! in_array($inbox->id, $scope, true))) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            throw new ApiException(ErrorCode::Unauthenticated);
        }
        $previous = $previousToken ? $this->authenticate($previousToken) : null;
        if ($previous !== null && $previous->inbox_id !== $inbox->id) {
            throw new ApiException(ErrorCode::Forbidden);
        }

        return DB::transaction(function () use ($inbox, $issuer, $claims, $previous): array {
            if (! DB::table('identity_exchanges')->insertOrIgnore(['id' => R::id(), 'workspace_id' => $inbox->workspace_id, 'issuer_id' => $issuer->id, 'jti_hash' => hash('sha256', $claims->jti), 'request_hash' => hash('sha256', $claims->sub.'|'.$inbox->id), 'expires_at' => now()->addMinutes(2), 'created_at' => now()])) {
                throw new ApiException(ErrorCode::IdentityReplayed);
            }
            // 同一 issuer/subject 的並發交換序列化，避免建立重複 contact。
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$inbox->brand_id.'|'.$issuer->issuer.'|'.$claims->sub]);
            $identity = DB::table('contact_identities')->where('brand_id', $inbox->brand_id)->where('issuer', $issuer->issuer)->where('subject', $claims->sub)->first();
            $contact = $identity->contact_id ?? R::id();
            if ($identity === null) {
                DB::table('contacts')->insert(['id' => $contact, 'workspace_id' => $inbox->workspace_id, 'brand_id' => $inbox->brand_id, 'name' => '會員', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('contact_identities')->insert(['id' => R::id(), 'workspace_id' => $inbox->workspace_id, 'brand_id' => $inbox->brand_id, 'contact_id' => $contact, 'issuer' => $issuer->issuer, 'subject' => $claims->sub, 'verified_at' => now(), 'created_at' => now()]);
            }
            if ($previous !== null) {
                $this->revoke($previous->id, 'identity_changed');
            }

            // 匿名歷史不自動合併到會員，以免共用裝置洩漏他人歷史。
            return $this->create($inbox, $contact, 'verified', $issuer->id);
        });
    }

    public function refresh(string $token): array
    {
        $row = $this->tenant->asSystem(fn () => DB::table('visitor_refresh_tokens')->where('token_hash', hash('sha256', $token))->first());
        if ($row === null) {
            throw new ApiException(ErrorCode::Unauthenticated);
        }
        $this->tenant->enterWorkspace($row->workspace_id);
        $result = DB::transaction(function () use ($row): ?array {
            // 鎖 session 再鎖 refresh，統一鎖順序並阻止旋轉與撤銷互相競爭。
            $session = DB::table('visitor_sessions')->where('id', $row->session_id)->lockForUpdate()->first();
            $locked = DB::table('visitor_refresh_tokens')->where('id', $row->id)->lockForUpdate()->first();
            if ($locked->used_at !== null) {
                $this->revoke($session->id, 'refresh_replayed');

                return null;
            }
            if ($locked->revoked_at !== null || strtotime($locked->expires_at) <= time() || strtotime($session->refresh_expires_at) <= time()) {
                return null;
            }
            $this->assertActive($session);
            $access = bin2hex(random_bytes(32));
            $refresh = bin2hex(random_bytes(32));
            $expires = now()->addSeconds((int) config('yacs.identity.access_token_ttl_seconds'));
            $refreshExpires = now()->addSeconds((int) config('yacs.identity.refresh_idle_ttl_seconds'));
            $newId = $this->refreshRow($row->workspace_id, $session->id, $locked->family_id, $refresh, $refreshExpires);
            DB::table('visitor_refresh_tokens')->where('id', $locked->id)->update(['used_at' => now(), 'replaced_by' => $newId]);
            DB::table('visitor_sessions')->where('id', $session->id)->update(['access_token_hash' => hash('sha256', $access), 'access_expires_at' => $expires, 'refresh_expires_at' => $refreshExpires, 'updated_at' => now()]);

            return $this->tokens($session->id, $access, $refresh, $expires->toISOString(), $session->identity_level);
        });
        // 重用撤銷必須先 commit，不能因回傳 401 而被交易 rollback。
        if ($result === null) {
            throw new ApiException(ErrorCode::Unauthenticated);
        }

        return $result;
    }

    public function revoke(string $id, string $reason): void
    {
        DB::table('visitor_sessions')->where('id', $id)->update(['revoked_at' => now(), 'revoked_reason' => $reason, 'session_generation' => DB::raw('session_generation + 1')]);
        DB::table('visitor_refresh_tokens')->where('session_id', $id)->update(['revoked_at' => now()]);
    }

    private function refreshRow(string $workspace, string $session, string $family, string $token, mixed $expires): string
    {
        $id = R::id();
        DB::table('visitor_refresh_tokens')->insert(['id' => $id, 'workspace_id' => $workspace, 'session_id' => $session, 'family_id' => $family, 'token_hash' => hash('sha256', $token), 'expires_at' => $expires, 'created_at' => now()]);

        return $id;
    }

    private function tokens(string $id, string $access, string $refresh, string $expires, string $level): array
    {
        return ['visitor_session_id' => $id, 'access_token' => $access, 'refresh_token' => $refresh, 'token_type' => 'Bearer', 'expires_at' => $expires, 'identity_level' => $level];
    }
}
