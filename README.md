# YACS 客服系統

可自行部署的繁體中文客服系統，包含網站聊天元件、人工客服工作台、管理後台、知識庫與 AI 草稿／自動回覆。訊息與背景工作保存在 PostgreSQL；Redis、Reverb 中斷後仍能查詢歷史。

## 開始測試

需要 Docker Compose v2、Node.js 24。首次安裝：

```bash
scripts/setup-demo.sh
```

腳本建立開發資料庫、安裝依賴、產生金鑰、編譯前端、套用 migration、建立示範帳號並啟動服務。不會清空既有資料或重設示範帳號。

| 入口 | 本機網址 |
|---|---|
| 測試商店與聊天元件 | http://localhost:8000/demo |
| 客服工作台 | http://localhost:8000/agent |
| 管理後台 | http://localhost:8000/ops |
| 開發郵件收件匣 | http://localhost:8025 |

示範管理員與客服密碼隨機產生，保存在 **gitignored** 的 `storage/app/private/demo-access.json`。執行 `docker compose exec app php artisan yacs:demo` 可重新查看。正式環境不提供示範商店／帳號。

先用訪客瀏覽器傳送問題，再以管理員登入工作台、接手、回覆與結案。詳細操作與 AI、附件、知識庫、API 測試步驟見 [測試手冊](docs/manual/zh-TW/testing.md)。

## 已提供的功能

- 工作空間／品牌／收件匣隔離；角色範圍、邀請、停用、密碼重設與 MFA。
- 訪客匿名／簽名會員身分、旋轉 refresh token、歷史訊息、FAQ、轉人工、CSAT；可嵌入的 MIT SDK。
- 人工接手、轉派、容量限制、在線心跳、待辦自動分派、內部備註、等待會員、稍後處理、72 小時重新開案、快捷回覆與標籤。
- 私有附件、S3／R2 物件加密、非同步內容檢查、圖片重編碼去除 EXIF、PDF 頁數限制、短效且重新授權的下載網址。
- 知識版本、索引、審核發布／撤回、品牌／可見性過濾、中文全文檢索；可配置 pgvector embedding 索引。
- Laravel AI SDK 隔離的文字 adapter，以及原生 Responses、Chat Completions、Anthropic Messages、Gemini GenerateContent 協定；assist 草稿需人工送出，auto_reply 受 lease／epoch／知識版本檢查。
- 最多兩次模型呼叫、同連線備援、每日呼叫預算、短期失敗熔斷、逾時獨立 watchdog 轉人工。
- Scoped API token、通用 HMAC 渠道雙向文字、去重、出站重試、投遞回報；獨立安全 outbox webhook、報表與私有匯出。

預設 AI 關閉。新增連線與模型後需通過實際 probe，再建立 AI 設定並套用到收件匣。外部 embedding 不可用時，人工與已發布的中文全文檢索仍可使用。

本交付提供可操作的客服閉環；原規格的 85 項驗收並未全部宣告完成。第三方 LINE／WhatsApp adapter、OCR、URL 匯入、案件 AI 摘要、業務工具、完整自動化規則與資料刪除／備份還原演練不包含在此版。實際測試與外部限制見 [交付審查](docs/REVIEW.md)。

## 開發與驗證

PHP 8.4（PDO PostgreSQL、Redis、GD、intl、mbstring）、Composer 2、Poppler。依賴與映像鎖定方式見 [依賴表](docs/DEPENDENCIES.md)。

```bash
composer install
npm ci
docker compose -f compose.test.yaml up -d --wait postgres redis
scripts/artisan-test.sh test
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
npm run types:api
npm run typecheck
npm test
npm run build
npx playwright install --with-deps chromium
npm run test:browser
```

後端測試使用獨立 PostgreSQL 18、pgvector、PGroonga，runtime 角色受 RLS 約束；瀏覽器測試使用本機示範環境並建立測試案件。CI 執行同類檢查及 production 映像建置，外部 AI／R2 live probe 不使用 CI 秘密。

OpenAPI 位於 `docs/spec/contracts`，前端型別與管理表單由 `npm run types:api` 產生。新增 API 同時修改契約、路由、授權、DTO 與測試。

## 部署與串接

- [正式部署](docs/manual/zh-TW/deployment.md)
- [設定與 R2](docs/manual/zh-TW/configuration.md)
- [網站 SDK、會員身分與通用渠道](docs/manual/zh-TW/integration.md)
- [原始設計與驗收](docs/spec/README.md)、[實作進度](docs/PROGRESS.md)

後端與工作台採 AGPL-3.0-only；網站 SDK 採 MIT。提供修改版網路服務時請保留適當的原始碼取得方式。
