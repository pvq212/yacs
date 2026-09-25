# ADR-0005：以 Octane + FrankenPHP 執行，長駐狀態隔離

- 狀態：採納
- 日期：2026-09-26

## 決策

- HTTP 以 **Laravel Octane + FrankenPHP** 執行（單一 binary，內建 Caddy，可自動 HTTPS）。
- 長駐程序風險控管：
  - 目前 workspace、使用者、AI provider 憑證、locale 不放在 singleton/static；以 scoped binding
    （每請求重建）或明確參數傳遞。
  - `TenantDatabase::reset()` 於 `RequestReceived`/`RequestTerminated` 與 queue job 前後執行。
  - `SEC-006` 測試在同一 worker 交錯服務 workspace A/B，驗證 context、locale、憑證不殘留。
- Reverb、Horizon、scheduler 為獨立程序（同一映像不同 command）。

## 影響

- 新增 service 時需判斷是否持有請求狀態；持有者必須 `scoped()` 綁定。
