<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use App\Support\Http\Principal;
use App\Support\Security\SecretBox;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * HTTP 冪等（docs/spec/API_CONTRACT.md §3、SPEC §09.2）。
 *
 * 流程（全部在同一個 DB 交易內，與業務寫入原子提交）：
 *  1. 以 (workspace, principal, method, route, key) 插入保留紀錄；
 *     若已存在（其他請求已提交）則放棄本交易：body hash 相同 → 重播原回應；不同 → 409。
 *     並發的同 key 請求會在唯一索引上等待對方提交，因此不會同時執行兩次。
 *  2. 執行 controller；回應為 2xx 時保存回應並提交，4xx/5xx 則 rollback（呼叫者可修正後重試）。
 *
 * 參數 `encrypt`：回應含短期 token 時以 envelope encryption 保存（例如身分交換）。
 * 保存 24 小時（`yacs.messages.idempotent_response_retention_hours`），到期由排程清除；
 * 到期後仍由訊息層的長期唯一鍵防止重複（MSG-003）。
 */
final class EnsureIdempotency
{
    public const HEADER = 'Idempotency-Key';

    public function __construct(private readonly SecretBox $secrets) {}

    public function handle(Request $request, Closure $next, string $mode = 'plain'): Response
    {
        $key = (string) $request->headers->get(self::HEADER, '');
        if (preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $key) !== 1) {
            throw new ApiException(ErrorCode::ValidationFailed, null, ['fields' => [self::HEADER => ['required_16_to_128_safe_characters']]]);
        }

        /** @var Principal $principal */
        $principal = app(Principal::class);
        $scope = [
            'workspace_id' => $principal->workspaceId(),
            'principal_scope' => $principal->principalScope(),
            'method' => $request->getMethod(),
            'route_fingerprint' => $this->routeFingerprint($request),
            'key' => $key,
        ];
        $requestHash = hash('sha256', $this->canonicalBody($request));

        $connection = DB::connection();
        $connection->beginTransaction();
        try {
            $reserved = $connection->table('idempotency_records')->insertOrIgnore([
                ...$scope,
                'id' => (string) Str::uuid7(),
                'request_hash' => $requestHash,
                'created_at' => CarbonImmutable::now(),
                'expires_at' => CarbonImmutable::now()->addHours((int) config('yacs.messages.idempotent_response_retention_hours', 24)),
            ]);

            if ($reserved === 0) {
                $connection->rollBack();

                return $this->replay($connection, $scope, $requestHash);
            }

            $response = $next($request);

            if (! $response instanceof JsonResponse || $response->getStatusCode() >= 400) {
                $connection->rollBack();

                return $response;
            }

            $body = (string) $response->getContent();
            $encrypt = $mode === 'encrypt';
            $connection->table('idempotency_records')->where($scope)->update([
                'response_status' => $response->getStatusCode(),
                'response_body' => $encrypt ? $this->secrets->encrypt($body, 'idempotency:'.$key) : $body,
                'response_encrypted' => $encrypt,
            ]);
            $connection->commit();

            return $response;
        } catch (Throwable $e) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, string>  $scope
     */
    private function replay(ConnectionInterface $connection, array $scope, string $requestHash): Response
    {
        $record = $connection->table('idempotency_records')->where($scope)->first();
        if ($record === null || $record->response_status === null) {
            // 對方交易已 rollback 或尚未完成（理論上不會發生，因為插入會等待對方提交）。
            throw new ApiException(ErrorCode::TemporarilyUnavailable, null, ['reason' => 'idempotency_in_progress']);
        }
        if (! hash_equals((string) $record->request_hash, $requestHash)) {
            throw new ApiException(ErrorCode::IdempotencyConflict);
        }
        if (CarbonImmutable::parse((string) $record->expires_at)->isPast()) {
            throw new ApiException(ErrorCode::IdempotencyConflict, null, ['reason' => 'expired']);
        }

        $body = (bool) $record->response_encrypted
            ? $this->secrets->decrypt((string) $record->response_body, 'idempotency:'.$scope['key'])
            : (string) $record->response_body;

        return new JsonResponse($body, (int) $record->response_status, ['Idempotent-Replayed' => 'true'], json: true);
    }

    private function routeFingerprint(Request $request): string
    {
        $route = $request->route();
        $name = $route?->getName() ?? $route?->uri() ?? $request->path();
        $params = $route?->parameters() ?? [];
        ksort($params);

        return mb_substr($name.'|'.http_build_query($params), 0, 200);
    }

    /**
     * 正規化 body：key 排序後重新編碼，避免空白/順序差異造成誤判衝突。
     */
    private function canonicalBody(Request $request): string
    {
        $data = json_decode($request->getContent() ?: '{}', true);
        if (! is_array($data)) {
            return $request->getContent();
        }
        $sort = static function (array &$value) use (&$sort): void {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$child) {
                if (is_array($child)) {
                    $sort($child);
                }
            }
        };
        $sort($data);

        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
