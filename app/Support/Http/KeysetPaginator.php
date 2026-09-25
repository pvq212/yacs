<?php

declare(strict_types=1);

namespace App\Support\Http;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Keyset（cursor）分頁：避免長歷史使用 OFFSET。
 *
 * cursor 只是「上一頁最後一列的排序值」的 base64url JSON，不含授權資訊；
 * 被竄改最多只會跳到同一個已授權查詢中的其他位置。
 */
final class KeysetPaginator
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  已套用授權範圍的查詢
     * @param  list<array{0:string,1:'asc'|'desc'}>  $order  排序欄位（最後一欄須唯一，例如 id）
     * @param  Closure(TModel): array<string, mixed>  $transform
     */
    public static function paginate(Builder $query, array $order, int $limit, ?string $cursor, Closure $transform): CursorPage
    {
        $limit = max(1, $limit);
        $values = $cursor !== null ? self::decode($cursor, count($order)) : null;

        if ($values !== null) {
            // (a,b) > (x,y) 的展開式，支援混合方向。
            $query->where(function (Builder $outer) use ($order, $values): void {
                foreach ($order as $i => [$column, $direction]) {
                    $outer->orWhere(function (Builder $inner) use ($order, $values, $i, $column, $direction): void {
                        for ($j = 0; $j < $i; $j++) {
                            $inner->where($order[$j][0], '=', $values[$j]);
                        }
                        $inner->where($column, $direction === 'asc' ? '>' : '<', $values[$i]);
                    });
                }
            });
        }

        foreach ($order as [$column, $direction]) {
            $query->orderBy($column, $direction);
        }

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $next = null;
        if ($hasMore) {
            /** @var TModel $last */
            $last = $rows->last();
            $next = self::encode(array_map(
                static fn (array $o): mixed => self::scalar($last->getAttribute(self::attributeName($o[0]))),
                $order,
            ));
        }

        return new CursorPage($rows->map($transform)->values()->all(), $next);
    }

    public static function clampLimit(mixed $limit): int
    {
        $default = (int) config('yacs.messages.page_default', 50);
        $max = (int) config('yacs.messages.page_max', 100);
        if ($limit === null || $limit === '') {
            return $default;
        }

        return max(1, min($max, (int) $limit));
    }

    /**
     * @param  list<mixed>  $values
     */
    private static function encode(array $values): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($values)), '+/', '-_'), '=');
    }

    /**
     * @return list<mixed>
     */
    private static function decode(string $cursor, int $expected): array
    {
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $values = $json === false ? null : json_decode($json, true);
        if (! is_array($values) || count($values) !== $expected || ! array_is_list($values)) {
            throw ApiException::of(ErrorCode::ValidationFailed, ['cursor' => ['invalid']]);
        }
        foreach ($values as $value) {
            if (! is_scalar($value) && $value !== null) {
                throw ApiException::of(ErrorCode::ValidationFailed, ['cursor' => ['invalid']]);
            }
        }

        return $values;
    }

    private static function attributeName(string $column): string
    {
        $pos = strrpos($column, '.');

        return $pos === false ? $column : substr($column, $pos + 1);
    }

    private static function scalar(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }

        return $value;
    }
}
