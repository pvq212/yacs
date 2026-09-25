# SupportDesk｜交給本地 AI 的開發規格包

**版本1.0.0 · 2026-09-26 · 繁體中文**

本包定義一套Laravel + PostgreSQL/pgvector + Redis/Horizon + Reverb的自架客服。涵蓋網站JS SDK/漂浮泡泡、會員前台、客服工作台、運營後台、RAG與AI供應商抽象、通用API/Webhook與後續渠道。

**這不是已完成應用。** 不包含可直接部署的Laravel專案、真實API keys或已通過生產測試的聲明。OpenAPI是設計契約；SDK是TypeScript型別宣告與使用示例，並非SDK實作。

## 檔案與閱讀順序

| 檔案 | 用途 |
|---|---|
| READABLE_SPEC.html | 可離線瀏覽的整合閱讀版；機器可讀契約請使用完整文件包 |
| SPEC.md | 主規格：產品、三端功能、流程、安全、部署與完成定義 |
| DATA_MODEL.md | 領域資料表、FK/唯一鍵、索引、狀態/併發與向量版本 |
| AI_ADAPTERS.md | Chat/Embedding/Rerank、四種API協定、SDK選用、timeout/fallback/tools |
| API_CONTRACT.md | 認證、HTTP/SDK/即時事件、冪等、Webhook及待展開管理細項 |
| contracts/openapi.yaml | 核心API：94條path、124個操作、106個schemas；不是所有後續渠道的全量API |
| contracts/openapi.json | 相同API的JSON形式 |
| contracts/widget-sdk.d.ts | SDK方法、事件與型別契約 |
| contracts/*realtime-event.schema.json | 公開/內部即時事件JSON Schema；public必須用public validator |
| CONFIG_DEFAULTS.yaml | 身分、容量、AI、RAG、佇列、附件、Webhook及保留預設 |
| ACCEPTANCE_TESTS.md | 85個Given/When/Then驗收案例，涵蓋權限、競態、故障與前端 |
| acceptance-tests.yaml | 同一份測試需求的機器可讀版本，不是測試程式 |
| IMPLEMENTATION_PLAN.md | M0→M1純人工→M2 AI→M3生產化的工作清單與閘門 |
| AGENTS.md | 本地coding agent必讀工作規則 |
| examples/ | 嵌入頁、合法public event範例、宿主後端簽發函式示例 |
| .env.example | 待實作應用的參數示例，不是現成部署設定 |
| REFERENCES.md | 官方依據、查核日期與未驗證界線 |
| tools/validate_specs.py | 文件/契約一致性檢查，不代替應用測試 |
| DOCUMENT_QA.md | 本文件包實際檢查結果與未檢查項目 |

## 直接給本地AI的啟動指令

```text
請把這份文件包視為開發規格，不是現有程式。
先讀 AGENTS.md、SPEC.md、DATA_MODEL.md、AI_ADAPTERS.md、API_CONTRACT.md，
再讀 contracts/openapi.yaml、ACCEPTANCE_TESTS.md 與 IMPLEMENTATION_PLAN.md。

先檢查目標repo與既有變更，不覆寫我的工作。
依 M0 開始建立可執行專案、真實PostgreSQL/Redis測試環境與CI，
再完成M1純人工客服；M1通過後才做M2知識庫AI與M3通用串接/生產化。

務必實作資料隔離、訊息冪等、outbox、任務lease fencing、
人工接手後AI不得發布、知識版本與Embedding隔離。
供應商API用自有adapter包住現成SDK，不要在業務Controller寫vendor判斷。

每個task都補migration、Policy、DTO、OpenAPI與測試。
未定義的管理細項API先補contract再實作。
每階段回報實際執行的命令/結果、未驗證的外部服務與已知限制。
不要用假測試結果或空成功回應宣稱完成；未有API key時用fixture並標明未實機驗證。
```

## 首版與後續範圍

M0–M3合起來是第一版正式試營運範圍。首版支持Web/hosted page/generic API/Webhook及可選AI；LINE、Telegram、Email列P2；WhatsApp/Messenger/Instagram、SSO、MCP、OCR等列P3或按需求提前，均需獨立完整驗收。

省略微服務、Dify、獨立向量資料庫與自主多Agent是刻意的架構決策。先以一個Laravel專案提供三個UI入口；AI慢工作、文件索引、事件/渠道投遞以不同worker/supervisor隔離資源。
