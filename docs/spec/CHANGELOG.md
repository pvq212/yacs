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

## 可測試交付（2026-09-30）

- 補齊人工客服、訪客、知識、AI、附件、渠道、管理與匯出的 OpenAPI；前端型別／管理 schema 由契約產生，測試比對全部 operation 的 method 與 route。
- CSAT 明確綁定 resolution cycle，內部標籤事件只進 staff audience；staff message DTO 增加公開渠道投遞狀態。
- 上傳完成回 202／quarantined，背景掃描成功才可附加；短效應用下載重新授權。新 S3／R2 物件以 envelope 加密，檔案記錄自己的磁碟與格式，既有物件不自行搬移。
- 會員 issuer 的 secret 使用至少 32 bytes 原始金鑰的 base64；JWT 維持 HS256、60 秒有效與單次 jti。
- 模型 probe 僅接受已宣告的文字／embedding 能力，提交前重新確認連線與模型版本；未實作的摘要、URL 匯入及渠道附件明確拒絕。
- 本版的實際驗證與尚未完成項目見 `docs/REVIEW.md`，不宣告原規格 85 項全數通過。
