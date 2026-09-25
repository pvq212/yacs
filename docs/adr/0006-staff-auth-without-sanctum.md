# ADR-0006：Staff 認證自建 session + CSRF，不使用 Sanctum/Fortify

- 狀態：採納
- 日期：2026-09-26

## 背景

原規格建議 Sanctum SPA 模式。YACS 的 widget iframe 與後台同源，Sanctum 的
`EnsureFrontendRequestsAreStateful` 以 Origin/Referer 判斷是否啟用 session，會讓
widget 的 bearer 請求也被套上 session/CSRF，造成兩種認證混用。

## 決策

- Staff API 使用獨立 middleware group `staff`：加密 cookie、session、CSRF（`X-XSRF-TOKEN`）、
  `auth` 與 `EnsureStaffSession`（檢查 MFA、撤銷、停用）。
- `GET /api/v1/auth/csrf-cookie` 取代 `/sanctum/csrf-cookie`，回 204 並設定 `XSRF-TOKEN` cookie。
- Widget、Integration、Webhook 各有獨立 group，不載入 session/cookie middleware。
- 登入、MFA（TOTP，`pragmarx/google2fa`）、邀請、密碼重設以自有 Action 實作，不引入 Fortify。

## 影響

- 移除 Sanctum 依賴；OpenAPI 路徑同步修改。
