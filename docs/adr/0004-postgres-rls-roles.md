# ADR-0004：PostgreSQL 角色分離與 Row Level Security

- 狀態：採納
- 日期：2026-09-26

## 背景

規格要求 workspace 資料隔離以「應用層 scope + 複合外鍵 + 多 workspace 攻擊測試」為基本，
RLS 可作第二層防護。首版就導入 RLS 的成本最低，事後補上需要逐表遷移與大量回歸測試。

## 決策

1. 三個資料庫角色（由 `infra/docker/postgres/initdb/10-yacs-roles.sh` 建立）：
   - `yacs_owner`：擁有 schema，只供 migration（Laravel connection `pgsql_migrator`）。
   - `yacs_runtime`：應用程式執行期連線（`pgsql`），非 owner、`NOBYPASSRLS`，只有 DML 權限。
   - `yacs_system`：`NOLOGIN BYPASSRLS`，runtime 可 `SET ROLE yacs_system`（不繼承權限）。
2. 所有含 `workspace_id` 的業務表啟用 RLS，policy 為
   `workspace_id = yacs_current_workspace_id()`（讀取 session 設定 `yacs.workspace_id`）。
   未設定時為 NULL → 看不到也寫不進任何列（fail closed）。
3. 應用程式以 `App\Support\Tenancy\TenantDatabase` 管理 session 設定：
   - 已驗證的 staff 請求在確認 membership 後 `enterWorkspace()`；
   - 訪客/整合 token 以 `asSystem()` 查出所屬 workspace 後再 `enterWorkspace()`；
   - 跨 workspace 的系統工作（dispatcher、watchdog、保留期清理）明確使用 `asSystem()`。
   - 每個 HTTP 請求與 queue job 開始/結束都 `reset()`，避免 Octane 長駐程序殘留。
4. append-only 表（稽核、指派歷史、訊息刪改紀錄等）以 trigger 禁止 UPDATE；DELETE 只允許
   在保留期清理交易內 `SET LOCAL yacs.purge = 'on'`。
5. 使用連線池（例如 PgBouncer transaction mode）時，必須改用 transaction-local 設定；
   首版部署不使用外部連線池，此限制記錄於部署手冊。

## 影響

- RLS 不取代 Laravel Policy；授權錯誤仍由應用層回 403/404，RLS 只防止程式疏漏造成跨租戶讀寫。
- `asSystem()` 是高權限操作，code review 需特別注意其使用點；測試涵蓋「未設定 workspace 時看不到資料」。
