<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\Support\Http\ApiException;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Facades\DB;

/**
 * 設定類資源的 compare-and-swap 更新（DATA_MODEL §1）：
 * `UPDATE ... SET ..., version = version + 1 WHERE id = ? AND version = ?`。
 *
 * 版本不符時回 409 VERSION_CONFLICT 並附上目前版本，呼叫端應重新載入後再送出。
 */
final class Cas
{
    /**
     * @param  array<string, mixed>  $changes  已驗證、可直接寫入的欄位（jsonb 欄位請先 json_encode）
     */
    public static function update(EloquentModel $model, string $expectedVersion, array $changes): void
    {
        $table = $model->getTable();
        $changes['version'] = DB::raw('version + 1');
        if ($model->usesTimestamps()) {
            $changes['updated_at'] = now();
        }

        $affected = DB::table($table)
            ->where($model->getKeyName(), $model->getKey())
            ->where('version', $expectedVersion)
            ->update($changes);

        if ($affected !== 1) {
            $current = DB::table($table)->where($model->getKeyName(), $model->getKey())->value('version');
            throw ApiException::versionConflict((string) ($current ?? '0'));
        }

        $model->refresh();
    }

    /**
     * 只檢查版本（例如需要先鎖定再做多表更新時）。
     */
    public static function assertVersion(int|string $currentVersion, string $expectedVersion): void
    {
        if ((string) $currentVersion !== $expectedVersion) {
            throw ApiException::versionConflict((string) $currentVersion);
        }
    }
}
