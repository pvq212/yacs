# 官方參考資料與查核紀錄

查核日期：2026-09-26。這些資料支持框架/協定/SDK狀態；本文件包中的架構、數值、角色、API與驗收流程是專案設計，並非官方產品承諾。套件實際版本與相容性仍須M0鎖定並測試。

| ID | 來源與用途 | 官方網址 |
|---|---|---|
| R01 | Laravel 13 release notes；PHP支援與版本基線 | `https://laravel.com/docs/13.x/releases` |
| R02 | Laravel AI SDK；供應商、功能矩陣、自訂base URL/相容provider | `https://laravel.com/docs/13.x/ai-sdk` |
| R03 | Laravel Reverb；事件迴圈、連線、部署與scaling | `https://laravel.com/docs/13.x/reverb` |
| R04 | Laravel Horizon；Redis queue與Redis Cluster限制 | `https://laravel.com/docs/13.x/horizon` |
| R05 | Laravel Sanctum；SPA session/cookie與API token區別 | `https://laravel.com/docs/13.x/sanctum` |
| R06 | pgvector官方repo；精確/ANN檢索、向量維度、索引與過濾 | `https://github.com/pgvector/pgvector` |
| R07 | PostgreSQL Row Security；owner/BYPASSRLS與policy | `https://www.postgresql.org/docs/current/ddl-rowsecurity.html` |
| R08 | Laravel Queue；retry_after/timeout/重試/transaction | `https://laravel.com/docs/13.x/queues` |
| R09 | OpenAI官方libraries；PHP列在community libraries | `https://developers.openai.com/api/docs/libraries` |
| R10 | Anthropic官方PHP SDK；本次文件仍標beta | `https://platform.claude.com/docs/en/cli-sdks-libraries/sdks/php` |
| R11 | Google Gemini官方GenAI libraries；語言與維護清單 | `https://ai.google.dev/gemini-api/docs/libraries` |
| R12 | MDN postMessage；origin/source/targetOrigin安全 | `https://developer.mozilla.org/en-US/docs/Web/API/Window/postMessage` |
| R13 | LINE接收訊息官方說明；webhookEventId重複與亂序 | `https://developers.line.biz/en/docs/messaging-api/receiving-messages/` |
| R14 | JWT PHP library維護來源；原firebase/php-jwt頁會導向googleapis repo | `https://github.com/firebase/php-jwt` |
| R15 | Telegram Bot API官方reference | `https://core.telegram.org/bots/api` |
| R16 | PostgreSQL text search parser說明；中文檢索仍需本案測試 | `https://www.postgresql.org/docs/current/textsearch-parsers.html` |
| R17 | OWASP SSRF Prevention；allowlist/redirect/網路防護設計依據 | `https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html` |
| R18 | OpenAI Responses遷移與格式 | `https://developers.openai.com/api/docs/guides/migrate-to-responses` |
| R19 | Gemini GenerateContent官方API reference | `https://ai.google.dev/api/generate-content` |

## 本次未宣稱已驗證的項目

沒有執行任何真實provider或第三方渠道呼叫、壓測、DB migration、Horizon/Reverb實機併發或瀏覽器端SDK；文件中的案例是待實作驗收要求。

本次未取得可完整讀取的Meta WhatsApp webhook頁內容，因此只列為後續架構需求，不列出具體帳號資格、訊息窗口、審核程序或收費數字；P3實作前應重新查核當時官方文件。

特定模型名稱/價格/最大context不寫死；由管理員配置後probe與能力驗證。本文不作資料保留法律合規結論，正式政策由資料負責人確認。
