<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 所有 API FormRequest 的基底。
 *
 * - 與 OpenAPI `additionalProperties: false` 一致：未定義於 rules() 的頂層欄位一律拒絕（422），
 *   避免呼叫者以為 workspace_id、author_type 之類欄位會生效。
 * - 授權（Policy/Authorizer）不在 FormRequest 中處理，由 Action 依資源範圍判斷；
 *   因此 authorize() 固定回 true，但驗證失敗一律回 VALIDATION_FAILED。
 * - 數字/布林以 strict 規則驗證，bigint 序號一律要求十進位字串。
 */
abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 允許的查詢參數（GET 請求）；預設不檢查 query。
     *
     * @return list<string>|null
     */
    protected function allowedQuery(): ?array
    {
        return null;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = [];
            foreach (array_keys($this->rules()) as $key) {
                $allowed[explode('.', (string) $key)[0]] = true;
            }
            $body = $this->isMethod('GET') ? [] : $this->json()->all();
            foreach (array_keys($body) as $key) {
                if (! isset($allowed[$key])) {
                    $validator->errors()->add((string) $key, 'unknown_field');
                }
            }
            $allowedQuery = $this->allowedQuery();
            if ($allowedQuery !== null) {
                foreach (array_keys($this->query()) as $key) {
                    if (! in_array($key, $allowedQuery, true)) {
                        $validator->errors()->add((string) $key, 'unknown_parameter');
                    }
                }
            }
        });
    }

    /**
     * 驗證用資料：GET 取 query，其餘只取 JSON body（不混入 query，避免參數走私）。
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->isMethod('GET') ? $this->query() : $this->json()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        /** @var array<string, mixed> */
        return $this->validated();
    }

    public static function seqRule(): string
    {
        return 'regex:/^(0|[1-9][0-9]{0,18})$/';
    }
}
