# 分階段實作計畫與交付檢查

本計畫不預估工期。每個階段以可執行、可驗收的成果為準，不以產生多少行程式或畫面數量為準。

## M0｜可測試的安全基礎

| Task | 交付與判定 |
|---|---|
| M0-01 | 建立repo與dependency matrix：PHP8.4/Laravel13實際patch、SDK、Vue/TS/Vite、PG/vector、Redis、license與lockfile；先跑最小安裝/測試 |
| M0-02 | Docker dev/test，app/PG/vector/Redis queue/cache/Reverb/worker分服務；.env只示例不帶secret；health checks |
| M0-03 | workspace/brand/inbox/users/membership/role資料表及composite FK；2 workspace ×2 brand測試seed |
| M0-04 | Staff session、邀請/密碼重設/MFA與Policies；補齊相關OpenAPI；Owner/Admin/Supervisor/Agent/KB角色 |
| M0-05 | Request context/ActorContext、顯式DTO、錯誤格式、idempotency、request/trace id、secret handling |
| M0-06 | CI：unit/integration/static/typecheck/contract/lint；真實PG並發測試工具與provider fake server |
| M0-07 | 通過SEC-001～006；記錄尚未啟用的production控制，無條件繼續前先補資料隔離問題 |

不引入多租戶註冊/計費；可用CLI建立第一位Owner。Demo帳密只在local seed生成並標示，正式seed不能固定密碼。

## M1｜完整純人工客服

| Task | 交付與判定 |
|---|---|
| M1-01 | contacts/identities/visitor session、JWT交換、refresh rotation/revocation；完成ID-001～006 |
| M1-02 | conversation/message/state machine、row locks、version/epoch、source uniqueness與read markers |
| M1-03 | assignment/capacity、available/heartbeat、等待會員、結案/重開/snooze/轉派；MSG-005～012 |
| M1-04 | async_tasks、outbox、audience-specific realtime events；先接fake publisher再Reverb；MSG-001～004/013～014 |
| M1-05 | Widget獨立Vue/TS build、loader/iframe、泡泡/embedded/hosted、SDK d.ts方法與events、first-party demo host |
| M1-06 | 客服工作台：列表/搜尋/對話/內部備註/客戶sidebar/快捷回覆/附件/結案；草稿不因409遺失 |
| M1-07 | 運營基礎：brands/inboxes/origins/staff/team/roles、營業時間/假日、巨集/標籤；每個細項先補OpenAPI |
| M1-08 | 檔案上傳quarantine/scan/download、權限與上傳配額；FILE-001～003 |
| M1-09 | Reverb auth、session fanout、撤權、重連/補播/snapshot/fallback polling；SEC-007～010、ID-007～010 |
| M1-10 | M1所有測試 + UI-001～004通過；展示匿名→會員、要求人工、接單回覆→結案→重開完整流程 |

**M1閘門：任何AI key不存在時仍可完整使用人工客服。** 基本知識/FAQ可先展示核准固定資料，不用假的AI回答冒充M2。

## M2｜可替換AI + 自建RAG

| Task | 交付與判定 |
|---|---|
| M2-01 | provider connections/model capability/ChatGateway/EmbeddingGateway/RerankGateway；LaravelAiAdapter第一個實作 |
| M2-02 | 四協定fixtures及error/stream/usage/unsupported測試；至少一個real Chat與Embedding live驗證，其他明確標未實測 |
| M2-03 | 知識資料表、FAQ/Markdown/TXT/PDF parser interface、病毒掃描整合、immutable draft/index/publish、來源scope |
| M2-04 | Embedding profile版本、維度檢查、新舊索引並存、atomic activation；小資料精確搜尋，ANN按壓測引入 |
| M2-05 | 中文FAQ aliases/lexical/vector融合、候選授權、source引用、staff測試提問頁 |
| M2-06 | AutopilotRun狀態、debounce、期限、epoch/lease/generation final commit、handoff、獨立watchdog |
| M2-07 | assist_only草稿/摘要、AI kill switch、原生能力probe、預算預留/usage、allowlisted fallback |
| M2-08 | ToolBroker readonly示例：本地fake「查本人案件」，ownership enforced；不接真帳務寫入 |
| M2-09 | 運營知識編輯/審核/發布/撤下/版本比較、AI設定/品質頁與150題評測資料格式 |
| M2-10 | AI-001～016、RAG-001～009、FILE-004；輸出模型/檢索評測與已知無答案情境 |

**M2閘門：人類先接手提交後，任何晚回AI答案都無法公開發布；知識撤下與換Embedding無混用。**

## M3｜通用串接與生產驗收

| Task | 交付與判定 |
|---|---|
| M3-01 | Scoped API clients、contact upsert、generic channel inbound/outbound、HMAC簽章/重試/delivery_unknown |
| M3-02 | 通用ChannelAdapter與capabilities，不綁特定社群；connector管理、健康、歷史與手動重送 |
| M3-03 | 報表/CSAT/AI用量/匯出、audit、保留/刪除/erasure tombstones、權限重查 |
| M3-04 | Redis隔離/noeviction、Horizon資源、durable task recovery、outbox/watchdog/lease故障測試 |
| M3-05 | 反代/TLS/CSP/安全headers、MFA rollout、SSRF egress、依賴掃描與secret檢查 |
| M3-06 | 備份/PITR/附件還原、移轉/升級/回滾腳本、關鍵事故runbook；實測RPO/RTO |
| M3-07 | 參考資料量與負載的穩態/突發壓測，整理p95與資源瓶頸、正確性不變量 |
| M3-08 | 補齊全量OpenAPI/生成TS client、SDK範例與宿主身分簽發說明；INT/OPS/SEC餘下blocking測試 |

**M3閘門：85條設計驗收案例都有實際測試/證據對應；不能只有清單勾選。** 不達效能目標可先限制試營運容量，但必須明示限額，不能把未達標結果改名達標。

## P2 / P3｜按需要擴展

先選實際使用最多的一個外部渠道，推薦的規格展開順序是LINE、Telegram、Email（這是本案優先級，不代表產品能力排名）。每個connector有官方規則查核、授權、能力矩陣、fixtures、實際帳號端到端測試、故障/亂序/附件/重送驗收後才開feature flag。

之後才做WhatsApp/Messenger/Instagram、SSO、CRM現成connector、MCP、原生push、OCR、進階SLA/排班、多租戶商業化。新增渠道不得改壞既有消息/角色/AI域模型。

## 建議repo結構（待實作）

```text
app/
  Modules/
    Identity/ AccessControl/ Workspaces/ Contacts/
    Conversations/ Assignment/ Messages/ Knowledge/
    Ai/{Contracts,DTOs,Adapters,Actions,Policies}/
    Channels/{Contracts,Adapters,DTOs}/
    Integrations/ Files/ Audit/ Reporting/ Operations/
resources/
  staff-app/             # Vue/TS: /ops and /agent
  widget-app/            # isolated build
packages/
  widget-sdk/            # loader + public methods + declaration tests
database/{migrations,factories,seeders}/
tests/{Unit,Feature,Integration,Concurrency,Contract}/
e2e/                     # Playwright demo host + staff + visitor
docs/                    # this package + ADR + runbooks
infra/                   # dev/prod templates, no real secrets
```

此目錄是建議，不要求為每個資料表增加空interface。可依Laravel慣例調整，但對外contract與安全邊界不改。

## 正式交付檔案

應用程式、lockfiles、migrations、factory/seed、測試、完整OpenAPI、SDK build、部署/升級/回滾/備份/還原手冊、ENV參數說明、provider/channel實測矩陣、RAG評測集/結果、效能測試結果、SBOM/授權及已知限制。
