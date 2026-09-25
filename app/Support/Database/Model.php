<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use LogicException;

/**
 * YACS 所有 Eloquent model 的基底。
 *
 * - 主鍵為應用程式產生的 UUIDv7（HasUuids 預設），但對話內排序只依序號。
 * - 時間欄位為 timestamptz，以含時區與微秒的格式寫入。
 * - 禁止直接 JSON 序列化：API 一律經由 Resource/DTO 輸出，避免欄位意外外洩
 *   （docs/spec/SPEC.md §13「不可直接序列化 Eloquent model」）。
 * - 不做 mass-assignment 防護（$guarded = []），因為所有寫入都由 Action 以明確欄位組成；
 *   Controller/FormRequest 不得把 `$request->all()` 直接交給 model。
 */
abstract class Model extends EloquentModel
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        throw new LogicException(static::class.' must be exposed through an explicit Resource/DTO, not serialized directly.');
    }
}
