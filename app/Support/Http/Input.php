<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * 輕量的輸入驗證（與 ApiRequest 相同規則）：
 *  - 寫入請求只讀 JSON body，GET 只讀 query；
 *  - 未定義在 rules 的頂層欄位一律拒絕（對應 OpenAPI additionalProperties: false）；
 *  - 回傳只含已驗證欄位的陣列。
 */
final class Input
{
    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validate(Request $request, array $rules, bool $fromQuery = false): array
    {
        $data = $fromQuery || $request->isMethod('GET') ? $request->query() : $request->json()->all();

        $allowed = [];
        foreach (array_keys($rules) as $key) {
            $allowed[explode('.', (string) $key)[0]] = true;
        }

        $validator = Validator::make($data, $rules);
        $validator->after(static function ($validator) use ($data, $allowed): void {
            foreach (array_keys($data) as $key) {
                if (! isset($allowed[$key])) {
                    $validator->errors()->add((string) $key, 'unknown_field');
                }
            }
        });

        /** @var array<string, mixed> */
        return $validator->validate();
    }

    /**
     * bigint 版本/序號（十進位字串）的驗證規則。
     *
     * @return list<string>
     */
    public static function seq(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'string', 'regex:/^(0|[1-9][0-9]{0,18})$/'];
    }

    /**
     * @return list<string>
     */
    public static function uuid(bool $required = true, bool $nullable = false): array
    {
        return array_values(array_filter([$required ? 'required' : 'sometimes', $nullable ? 'nullable' : null, 'string', 'uuid']));
    }

    public static function isUuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /**
     * 列表查詢共用的 cursor/limit 規則。
     *
     * @return array<string, mixed>
     */
    public static function pageRules(): array
    {
        return [
            'cursor' => ['sometimes', 'string', 'max:512'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('yacs.messages.page_max', 100)],
        ];
    }
}
