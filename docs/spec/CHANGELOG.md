# 規格變更紀錄

原始規格包 v1.0.0（SupportDesk，2026-09-26）匯入於 commit「docs: 匯入原始規格包」。
以下列出之後的修訂；每項決策細節見 `docs/adr/`。

## 1.1.0（開發中）

- 專案更名為 YACS；header 前綴 `X-Yacs-*`、SDK 全域 `window.Yacs`、JWT audience `yacs:visitor`、
  cookie `yacs_session`（ADR-0002）。
- Staff CSRF 端點由 `/sanctum/csrf-cookie` 改為 `/api/v1/auth/csrf-cookie`，不使用 Sanctum（ADR-0006）。
- OpenAPI 錯誤回應改用 `components/responses` 共用定義（內容不變，僅去除重複）。
- 佇列補齊 events/channels 參數（ADR-0015）；PostgreSQL 角色分離與 RLS 首版啟用（ADR-0004）。
- `visitor_sessions` 移除 `refresh_hash`，refresh token 單一來源（ADR-0008）。
- 檔案預設不做病毒掃描，保留 Scanner 介面（ADR-0009）；中文檢索採 PGroonga（ADR-0010）。
- 移除衍生檔 `READABLE_SPEC.html`、`MANIFEST.sha256`（隨規格修訂即失效，改以 git 歷史追蹤）。
