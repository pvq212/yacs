# ADR-0008：訪客 refresh token 單一來源

- 狀態：採納
- 日期：2026-09-26

## 背景

原資料模型在 `visitor_sessions.refresh_hash` 與 `visitor_refresh_tokens.token_hash` 兩處保存
refresh token，兩者可能不一致。

## 決策

- `visitor_sessions` 只保存 access token hash 與 refresh 閒置到期時間。
- refresh token 只存在 `visitor_refresh_tokens`：每次 refresh 以 `SELECT ... FOR UPDATE` 鎖定
  該 token，標記 `used_at` 並建立新 token（`replaced_by`）。
- 已使用過的 token 再次出現 = reuse：撤銷整個 family 與 session（ID-004）。
- 匿名 resume token 即 refresh token（存宿主頁 sessionStorage）；access token 只放記憶體。
