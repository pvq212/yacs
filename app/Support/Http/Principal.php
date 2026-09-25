<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * 已驗證的請求主體（staff / visitor / integration）。
 *
 * 用於 HTTP 冪等紀錄的 scope：同一個 Idempotency-Key 只在同一主體、同一 workspace 內有意義。
 */
interface Principal
{
    public function workspaceId(): string;

    /**
     * 例如 `staff:<membership id>`、`visitor:<session id>`、`api:<client id>`。
     */
    public function principalScope(): string;
}
