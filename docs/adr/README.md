# 架構決策紀錄（ADR）

本目錄記錄 YACS 的重要架構決策。規格（`docs/spec/`）與實作若有衝突或需要取捨，
先在此新增 ADR，再修改規格與程式，不可默默選擇對實作最方便的解釋。

## 格式

每份 ADR 包含：狀態、背景、決策、影響（含已知代價）、替代方案。
狀態：`提議` → `採納`；被取代時標示 `由 ADR-XXXX 取代`，舊文件保留。

## 索引

| 編號 | 標題 | 狀態 |
|---|---|---|
| [0001](0001-record-architecture-decisions.md) | 以 ADR 記錄架構決策 | 採納 |
| [0002](0002-naming-and-license.md) | 專案命名 YACS 與授權（AGPL-3.0 / MIT） | 採納 |
| [0003](0003-queue-redis-horizon.md) | 佇列採 Redis + Horizon，PostgreSQL 為任務權威來源 | 採納 |
| [0004](0004-postgres-rls-roles.md) | PostgreSQL 角色分離與 Row Level Security | 採納 |
| [0005](0005-octane-frankenphp.md) | 以 Octane + FrankenPHP 執行，長駐狀態隔離 | 採納 |
| [0006](0006-staff-auth-without-sanctum.md) | Staff 認證自建 session + CSRF，不使用 Sanctum/Fortify | 採納 |
| [0007](0007-object-storage.md) | 物件儲存：S3 相容（正式 R2），瀏覽器預簽名直傳 | 採納 |
| [0008](0008-visitor-refresh-token-single-source.md) | 訪客 refresh token 單一來源 | 採納 |
| [0009](0009-file-validation-without-antivirus.md) | 檔案檢查：預設不掃毒，保留 Scanner 介面 | 採納 |
| [0010](0010-chinese-search-pgroonga.md) | 中文關鍵字檢索採 PGroonga + FAQ 關鍵字 | 採納 |
| [0011](0011-extension-registry.md) | 後端擴充機制（Extension Registry） | 採納 |
| [0012](0012-frontend-stack.md) | 前端技術選型 | 採納 |
| [0013](0013-outbound-integration-webhooks.md) | 對外事件出口首版僅 Webhook | 採納 |
| [0014](0014-no-observability-stack.md) | 首版不內建可觀測性堆疊 | 採納 |
| [0015](0015-queue-parameters.md) | 補齊 events/channels 佇列參數 | 採納 |
