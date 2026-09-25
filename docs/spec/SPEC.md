# YACS 自架 AI 客服平台｜產品與技術規格書

> 本文件源自規格包 v1.0.0，已依 `docs/adr/` 的決策修訂；與原版差異見 `CHANGELOG.md`。

**文件版本：1.0.0**  
**基準日期：2026-09-26（Asia/Taipei）**  
**專案名稱：YACS（Yet Another Customer Service）**  
**技術基線：Laravel + PostgreSQL / pgvector + Redis / Horizon + Reverb**  
**用途：交付本地 AI coding agent，分階段實作及驗收。這是開發規格，不是已完成的系統或效能保證。**

---

## 00. 文件使用方式與決策基線

先讀本文件，再依序讀 `DATA_MODEL.md`、`AI_ADAPTERS.md`、`API_CONTRACT.md`、`contracts/openapi.yaml`、`ACCEPTANCE_TESTS.md`、`IMPLEMENTATION_PLAN.md`。`AGENTS.md` 是 coding agent 的工作規則；`contracts/widget-sdk.d.ts` 是瀏覽器 SDK 的型別契約。

本文件定義產品行為與安全不變量；資料字典定義儲存與約束；OpenAPI 定義首版核心 HTTP 契約。發現衝突時，先修正文檔並留下 ADR（架構決策紀錄），不得由實作者默默選擇對自己最方便的解釋。API 契約中尚未收錄的管理 CRUD 必須在開發該功能之前補齊 request / response schema；不能拿泛用 JSON 當作整個系統的最終 API 定義。

### 00.1 已選定的預設

| 項目 | 本案決策 |
|---|---|
| 產品型態 | 單一公司自架，多品牌、多網站、多收件匣；資料層從第一天有 workspace 隔離 |
| 多租戶 | 首版可配置多 workspace 並測試隔離；不做公開註冊、方案訂閱、計費 SaaS |
| 後端 | 模組化單體 Laravel，不先拆 Go / Python 微服務 |
| PHP / Laravel | 新專案以 PHP 8.4、Laravel 13.x 為基線；建立專案時鎖定經 CI 驗證的修補版，不使用浮動 latest [R01] |
| 前端 | Vue 3 + TypeScript + Vite；客服與運營後台共用元件，widget 獨立 build |
| UI 傳輸 | 後台同站 session；業務讀寫 REST API；即時通知使用 Reverb；斷線以 HTTP 補資料 |
| 資料庫 | PostgreSQL 18.x + pgvector + PGroonga（自建映像）；migration 與 runtime 角色分離並啟用 RLS（ADR-0004） |
| 非同步 | Redis 非 Cluster 佇列 + Horizon；權威任務紀錄仍保存在 PostgreSQL（ADR-0003） |
| 執行環境 | Laravel Octane + FrankenPHP；Reverb、Horizon、scheduler 為獨立程序（ADR-0005） |
| 物件儲存 | S3 相容 API；正式環境 Cloudflare R2，開發用 SeaweedFS（ADR-0007） |
| 中文檢索 | PGroonga + FAQ 關鍵字，pg_trgm 為備援（ADR-0010） |
| 授權 | 主程式 AGPL-3.0-only；widget SDK 與 examples 採 MIT（ADR-0002） |
| AI | 第一版可完全關閉；啟用時經自有介面接入 Laravel AI SDK，必要時換原生/維護中客戶端 |
| 檢索 | 自建文件、chunks、embeddings；不把知識庫必然綁在模型供應商的檔案搜尋服務 |
| UI 語言 | 預設繁體中文；文字抽出 i18n，預留簡體中文與英文 |
| 時間 | DB 使用 UTC `timestamptz`；營業時間與畫面預設 Asia/Taipei，可按 workspace 設定 |
| 首版渠道 | Web widget、獨立客服頁、通用伺服器 API、通用入站/出站 Webhook |
| 前台 AI 顯示 | 預設先顯示「正在整理回覆」，通過檢查後一次發布完整答案；不先把未驗證 token 串給會員 |

**穩定原則：AI、向量索引、推送或第三方渠道故障，不得破壞已落地的人工客服訊息。** 不承諾單機高可用；人工服務也依賴 PostgreSQL 與正常運作的應用程序。

### 00.2 本文用語

MUST / 必須：首版必須完成。SHOULD / 建議：可用 ADR 說明替代設計。P2 / P3：後續擴充，不可冒充首版已支援。所有容量、逾時與品質數字都是本案起始設定或驗收目標，不是套件能力的實測聲明。

---

## 01. 目標、角色與範圍

### 01.1 核心目標

讓會員/訪客從網站的漂浮泡泡發起對話，AI 可依核准知識回答；無答案、要求人工、超時或政策限制時，轉入人工佇列。客服可接單、回答、留言、轉派、結案與重新開案。運營人員可管理知識、AI 設定、網站、座席、權限與稽核。

系統必須具備可重送訊息、可重連、可追查、可替換 AI 模型/供應商、可新增渠道的能力。UI 不能顯示成功但訊息尚未持久化。

### 01.2 領域層級

`Workspace（公司/租戶） → Brand（品牌） → Inbox（網站或渠道收件匣） → Conversation（對話） → Message（訊息）`。

Contact（客戶）在首版限定於 workspace + brand；同一自然人在不同品牌可有不同 Contact，禁止依同名、同 Email 自動合併。Staff User 可加入多 workspace，但每次請求只在一個明確、經授權的 workspace context 中執行。

### 01.3 功能邊界

| 里程碑 | 包含 |
|---|---|
| M0 基礎 | 版本鎖定、Docker 開發環境、權限、隔離、資料表、測試及 CI |
| M1 純人工閉環 | 運營基本設定、客服工作台、widget、身分、訊息、接單/轉派/結案、Reverb/重連、附件 |
| M2 AI + 知識 | FAQ/Markdown/TXT/文字型 PDF、版本發布、檢索、AI adapter、人機交接、評測、成本與停用 |
| M3 首版生產化 | 通用 API/Webhook、故障恢復、備份還原、監控、壓測、安全驗收 |
| P2 優先渠道 | LINE、Telegram、Email；每個渠道獨立驗收，不只是填一個 token |
| P3 擴充 | WhatsApp、Messenger、Instagram、OIDC/SAML SSO、CRM connector、MCP、進階 SLA/排班、語音與 OCR |

首版正式試營運定義為 M0–M3 全部通過；M1 可作為受控人工試用。首版不含付款/退款/轉帳的 AI 自動執行、不含任意 SQL/任意程式工具、不含所有社群平台一次接完、不含多 Agent 自主處理工作流程。

---

## 02. 系統架構與模組

### 02.1 部署單元

```text
網站 / WebView / 獨立客服頁          運營後台 / 客服工作台
              │                              │
              └────── HTTPS / REST ──────────┘
                              │
                        Laravel 應用
       ┌──────────────────────┼────────────────────────┐
       │                      │                        │
 PostgreSQL + pgvector    Redis + Horizon         私有物件儲存
 訊息 / 權限 / 知識 /      core / ai / kb /        文件 / 附件
 任務與事件 / 稽核        events / channels
       │                      │
       └──── Transactional Outbox ── Reverb ── WebSocket
                              │
                         Provider Adapter
                    OpenAI / Claude / Gemini /
                       相容 API / 本地模型
```

Reverb 是低延遲傳送管道，不是聊天紀錄資料庫。它提供事件迴圈與橫向擴展能力，但重連補資料、事件權限與重複處理是本案應用邏輯 [R03]。

### 02.2 程式模組

`Identity`、`Workspaces`、`AccessControl`、`Contacts`、`Conversations`、`Assignment`、`Messages`、`Knowledge`、`Ai`、`Channels`、`Integrations`、`Notifications`、`Audit`、`Reporting`、`Files`、`Operations`。

採 Controller → FormRequest/Policy → Application Action → Domain Service → Model/Query 的分層。不是每張資料表都包一層沒有功能的 Repository；只在外部 AI、渠道、儲存、搜尋或需要替換的位置設 interface。

瀏覽器送進來的 workspace_id、brand_id、contact_id、角色、餘額、VIP、是否已驗證等，不得直接當成授權事實。

### 02.3 長駐程序限制

可以部署於既有 Octane / RoadRunner / FrankenPHP，但先用整合測試證明跨請求 context、credentials、locale 不殘留。不可把目前 workspace、使用者、AI provider key 放入可跨請求共用的 singleton/global 狀態。一般 HTTP worker 不同步等待完整模型答案；AI 工作交給專用 queue。

---

## 03. 前台：網站與會員客服

### 03.1 顯示形態

F-WEB-001：支援右下/左下漂浮泡泡、嵌入指定 DOM 容器、獨立客服頁三種模式。桌面建議寬 380px / 高 620px，手機可切全螢幕並避開安全區與軟鍵盤；尺寸為可調設計值。

F-WEB-002：泡泡顯示未讀數、營業狀態與歡迎文案，可配置主色、標誌、客服稱呼、語言、位置和隱私說明。品牌設定只能採白名單 CSS token，不接受任意 JS 或 HTML。

F-WEB-003：前台包括歡迎/FAQ、目前對話、歷史對話、離線留言、檔案上傳、滿意度評分與明確「轉人工」入口。輸入框提供發送狀態、重試、附件狀態；IME 組字未完成時 Enter 不得誤送。

F-WEB-004：首版用跨來源 iframe 隔離宿主網站樣式；輕量 JS loader 控制泡泡/iframe。不得讓整個後台 JavaScript bundle 跟著載入宿主網站。

### 03.2 匿名與會員模式

匿名可直接問一般知識，建立受限訪客 session。會員模式由宿主「後端」產生短期身分 token，再交換本系統 visitor access token。前端只傳 user_id 或 Email 不構成認證。

預設匿名暫存使用宿主頁 `sessionStorage` 的不透明 resume token；短期 access token 僅留記憶體。清理瀏覽器儲存、換瀏覽器或匿名 token 過期後，不保證匿名歷史可恢復。會員可透過重新驗證恢復相同品牌內的歷史。

匿名轉會員預設新建會員 session，不自動把所有匿名歷史合併。可提供單一目前對話的明確合併流程：同時驗證匿名持有權及新會員身分，顯示同意提示、寫入 audit；不得改寫曾屬於另一已驗證會員的對話。

### 03.3 斷線與體驗

F-WEB-005：先保存本地待送 draft，收到 HTTP 持久化成功回應後才顯示「已送出」。重試沿用 `client_message_id` 與 `Idempotency-Key`，不生成新的訊息。

F-WEB-006：WebSocket 斷線採指數退避與抖動重連，使用該對話的持久事件 cursor 補資料；游標過期則抓取 snapshot。Fallback polling 起始 5 秒、背景分頁拉長至 30 秒，數值可調。

F-WEB-007：AI 狀態與人工等待分開顯示。客服無人在線時，顯示離線留言而非「已接通真人」。不顯示虛構排名、等待時間或實際不存在的客服。

F-WEB-008：WCAG 2.2 AA 作為 UI 驗收目標：鍵盤開關、focus trap/return、標籤、對比、可縮放、非純色狀態、合理 live region；測試不能只看 Lighthouse 分數。

### 03.4 隱私界線

預設只收集使用者主動提供的資訊、當前頁 path、語言及基本瀏覽器資訊；完整 URL query、fragment、DOM、螢幕錄影、session replay 均關閉。客服不得讀取會員密碼、OTP、私鑰或 API key。手機推播/Email 預設只有通知摘要，不塞入敏感對話全文。

---

## 04. 網頁 JS SDK 與嵌入安全

完整型別見 `contracts/widget-sdk.d.ts`，範例見 `examples/embed.html`。

### 04.1 對外方法

`init`、`open`、`close`、`toggle`、`identify`、`logout`、`setContext`、`setAttributes`、`sendMessage`、`requestHuman`、`on`、`off`、`destroy`。

`init` / `identify` / `logout` / `sendMessage` 等非同步方法必須回傳 Promise。SDK 未 ready 前，命令排隊但最多 50 筆；重複 init 不建立第二個 socket/iframe。SPA 換頁只更新經白名單處理的 context，不重建身分。

事件包括 `ready`、`opened`、`closed`、`unread:changed`、`message:received`、`conversation:updated`、`connection:changed`、`identity:changed`、`error`。事件只含該訪客可看的 DTO，不包含後台備註、secret、供應商原始回應或內部客戶欄位。

### 04.2 安全與身分交換

1. `inbox_key` 是公開識別碼，不是 API key；host origin allowlist 只限制嵌入環境，不能替代身分認證或抗濫用。
2. 宿主後端根據自己的已登入 session 產生 JWT。允許演算法固定 HS256；採維護中的 JWT 函式庫，不自行實作簽章。金鑰至少 256-bit 隨機值，具有 `kid` 且可輪換 [R14]。
3. Claims 至少含 `iss`、`aud="yacs:visitor"`、`sub`、`workspace_id`、`brand_id`、`inbox_key`、`iat`、`exp`、`jti`；有效期預設 60 秒，時鐘誤差最多 30 秒。伺服器將 issuer/key 綁到正確 workspace/brand/inbox，不能只相信 payload。
4. `jti` 以 DB 原子唯一約束執行一次性交換。同一請求的安全重試需由 idempotency 紀錄識別；不能為了網路重試取消 replay 防護。
5. 交換出的 visitor token 15 分鐘有效，限制到 contact + brand + inbox + session；refresh token 不透明、旋轉、DB 僅存 hash，最長閒置 24 小時。member refresh 必須受宿主 logout/revocation 流程控制，不能當成永久登入。
6. `logout` 必須撤銷訪客 session、清除草稿/記憶體/本地 resume token、關閉舊 socket，再以新匿名 session 重建；不能只改畫面姓名。
7. `postMessage` 驗證 `event.origin`、`event.source` 與 handshake nonce；發送指定精確 targetOrigin，不使用 `*` [R12]。
8. iframe 透過 inbox 設定輸出 CSP `frame-ancestors`，宿主需設定 script-src / frame-src / connect-src。禁止把 token 放在 iframe query、URL fragment、Referer、error log 或 analytics。
9. 已訂閱的 socket 不會因 token 到期自動變安全：必須有撤銷/斷線與短期重新授權機制；後端每次 publish 仍只送允許的受眾。

第一版 WebView 優先載入已允許的 HTTPS 頁面。原生 App 的 `null` / 自訂 origin 不直接加入通配 allowlist；另建 app-session bridge，在 P2 驗證 app 端身分交換及 token 儲存。

### 04.3 SDK 發佈

提供固定版本路徑 `/sdk/v1.0.0/yacs.js`、major alias `/sdk/v1/yacs.js` 與 NPM package（有需要才發佈）。正式建議鎖固定版本與 SRI；hash 在 build 時產生，不寫假值。CDN 只快取公開 loader/assets，不快取私人 API/對話/附件。

---

## 05. 客服工作台

路徑 `/agent`。至少包含左側收件匣/隊伍清單、對話清單、訊息主區、客戶與操作側欄；手機最低可讀可回覆，進階運營操作可要求桌面。

| 功能 ID | 規格 |
|---|---|
| F-AG-001 | 我的案件、可接佇列、等待會員、已結案、篩選/標籤/未讀/搜尋；分頁不可一次載入所有聊天 |
| F-AG-002 | 公開回覆與內部備註分開的輸入模式，顏色+文字標識，快捷鍵切換仍需清楚狀態 |
| F-AG-003 | 接單、手動轉派、轉團隊、主管強制接手、退回佇列、結案、重開、稍後處理 |
| F-AG-004 | 快捷回覆巨集、附件、引用訊息、草稿；巨集模板只允許核准變數並做 escaping |
| F-AG-005 | AI 助理可建議回覆、摘要、推薦知識；人工模式只寫草稿，不自行送給客戶 |
| F-AG-006 | 顯示使用的知識來源/版本、AI 執行狀態與可理解錯誤；僅有權限者可查看敏感 debug |
| F-AG-007 | 客戶身分標示「匿名 / 已驗證」，顯示品牌內紀錄與經授權的業務查詢結果 |
| F-AG-008 | 結案需選原因；可選內部摘要及標籤。結案不等於刪除；保留審計軌跡 |
| F-AG-009 | 回覆前若版本/負責人已改變，回傳衝突並保留草稿，不靜默覆蓋別人操作 |
| F-AG-010 | 新訊息音效、瀏覽器通知可選；多分頁以 leader/去重避免連續多次提示 |

客服狀態：`available`、`busy`、`away`、`offline`。前端每 30 秒 heartbeat；90 秒無 heartbeat 視為 offline，彙整多分頁而非任一分頁離線就登出。Presence 只影響分派候選，非權限來源。

客服離線不立即把正在處理的每個案件搶走。預設保留 5 分鐘，再對未讀且需要回覆的案件進入重新分派；waiting_customer 可維持負責人，主管可調整。全程記錄 assignment event。

---

## 06. 運營後台

路徑 `/ops`。首版至少有以下頁面與操作：

| 頁面 | 必須內容 |
|---|---|
| 總覽 | 待接、處理中、超時待處理、在線客服、AI 成功/轉人工、渠道異常；可按品牌/收件匣/日期篩選 |
| 公司與品牌 | workspace 名稱、時區、品牌、客服名稱、logo、隱私文案 |
| 收件匣 | 網站/渠道類型、嵌入代碼、允許 origin、營業時間、節假日、分派規則、容量、啟用狀態 |
| 人員與權限 | 邀請、啟用/停用、MFA、角色、團隊、品牌/收件匣授權、座席容量 |
| 知識庫 | 文件列表、建立/匯入、版本比較、編輯草稿、索引狀態、發布/退版/撤下、測試提問 |
| AI 設定 | provider connection、模型清單、能力、secret 更新、測試連線、聊天/embedding/rerank profile、預算、停用 |
| AI 品質 | 問題評測集、引用命中、無答案、轉人工原因、人工修正；不能把模型自評當唯一分數 |
| 巨集與規則 | 快捷回覆、結案原因、標籤、允許的自訂欄位、簡單條件/動作自動化 |
| 串接中心 | API token、issuer key、Webhook、重送紀錄、渠道健康、業務 API schema/secret |
| 報表 | 工作量、首回覆/解決時間、轉人工、CSAT、AI 用量；權限範圍內下載 CSV |
| 稽核 | 登入、權限改動、知識發布、provider 修改、匯出、閱覽敏感資料、轉派/結案、資料刪除 |
| 系統健康 | 對具備 platform operator 權限者顯示 queue、DB、Reverb、儲存與備份狀態 |

規則引擎首版只接受 typed JSON：條件 `inbox / tag / business_hours / verified_customer / language`，動作 `assign_team / add_tag / enqueue_human / send_approved_macro`。不執行 arbitrary PHP/JS/SQL，防止規則互相觸發循環；一次事件最多 10 個動作，含 `causation_id`。

---

## 07. 角色、權限與資料隔離

### 07.1 RBAC 加資源範圍

| 角色模板 | 預設能力 |
|---|---|
| Owner | workspace 管理、委派角色、資安與資料治理、營運；所有 secret 一樣只能寫入/遮罩不可原文查看 |
| Operations Admin | 收件匣、人員、AI/知識配置；不能自行變成 Owner 或賦予自己沒有的權限 |
| Supervisor | 指定團隊/收件匣的案件監督、轉派、報表、協助回覆 |
| Agent | 自己負責案件與有資格接取的未分派案件；不得預設查看其他人已接案件 |
| Knowledge Editor | 草稿/匯入/索引，不自帶發布權限或對話內容權限 |
| Knowledge Publisher | 審閱及發布核准範圍內知識；可與 Editor 分人 |
| Auditor | 範圍內稽核/去識別報表；聊天原文與匯出需另授權 |
| Integration Client | 機器 token，固定 scopes/品牌/收件匣；不是 Staff User |

權限碼至少包括 `workspace.manage`、`brand.manage`、`inbox.manage`、`staff.manage`、`roles.assign`、`conversation.read`、`conversation.claim`、`conversation.reply`、`conversation.note`、`conversation.assign`、`conversation.resolve`、`conversation.reopen`、`conversation.assist_other`、`contact.read_sensitive`、`knowledge.edit`、`knowledge.publish`、`ai.manage`、`integration.manage`、`report.read`、`export.create`、`audit.read`、`data.erase`。

每個 grant 帶 workspace 以及可選 brand/inbox/team scope。有效能力 = 已認證身分 + workspace membership 有效 + 動作權限 + 資源 scope；不可只因有同名 role 就授權所有品牌。

### 07.2 隔離要求

所有業務表、cache key、job、vector 查詢、附件路徑、匯出、推送與 webhook 都帶 workspace scope。列表、統計、搜尋結果數與客服 sidebar 也必須隔離，不只 detail endpoint。

RLS 可作第二層防護，但不是取代 Laravel Policy。首版至少做到 application scope + composite foreign keys + 多 workspace 攻擊測試。啟用 RLS 時用非 owner、非 BYPASSRLS 的 runtime role，對 pooled connection 使用 transaction-local context，避免跨工作殘留；migration role 與 runtime role 分開。PostgreSQL 明確指出 owner/特權角色具有繞過行為 [R07]。

使用者停用或權限撤銷後，既有 session、API token 和 socket 應在 60 秒內失效。稽核記錄保留操作者、授權 scope、原因及變更前後的非 secret 摘要。

---

## 08. 對話狀態機與人工分派

### 08.1 兩個獨立欄位

`status = open | waiting_customer | snoozed | resolved`。

`handling_mode = ai | human_queue | human`。

- 活躍的 `ai` / `human_queue` 必須 `status=open` 且 `assignee_id=null`。
- 活躍的 `human` 必須有有效歷史 assignee；assigned staff 被停用時由 supervisor/排程退回佇列。
- `waiting_customer` / `snoozed` 僅限 human。
- `resolved` 保留最後 handling_mode/assignee 作歷史資訊，但不得繼續執行 AI 或發新公開訊息；先重開才可回覆。

不把「結案」和「AI 接待」塞成一組互斥單欄位。`version` 用於人工操作的 optimistic concurrency；`answer_epoch` 用於廢止舊 AI 回覆，不能混用。

### 08.2 主要轉換

| 觸發 | 結果 |
|---|---|
| 新會話且 auto AI 啟用/服務可用 | open + ai |
| 未啟用 AI / 明確轉人工 / AI 故障 / 政策要求 | open + human_queue |
| 可用客服接單/自動分派成功 | open + human + assignee |
| 客服公開回覆並選「等待會員」 | waiting_customer + human |
| 會員回覆 waiting_customer | open + human，保留負責人；如失效則轉 human_queue |
| 客服稍後處理 | snoozed + human + wake_at；醒來重檢容量/人員，不適合則 human_queue |
| 人工結案 | resolved + resolution_code，釋放容量 |
| 已結案後會員在 72 小時內再發問 | 原對話重開；之前由人工接待則 human_queue，不能自行偷偷交回 AI |
| 超過重開視窗 | 建立新對話，保留 related_conversation_id |
| 人工明確「交還 AI」 | 取消 assignee、open + ai、增加 answer_epoch；記錄原因 |

AI 預設不能自行判定問題已解決。會員按「已解決」可結案；無回應自動結案需另啟用規則，且報表計為 `auto_inactive` 而非已證實解決。

### 08.3 分派規則

先依 workspace/brand/inbox/team/營業時間找 eligible agents，再檢查 available、heartbeat、容量；排序以 `last_assigned_at ASC, staff_id ASC` 輪流分派。預設每位客服活躍容量 5，可配置；`open + waiting_customer` 的人工案件計容量，resolved/snoozed 不計。

分派與接單必須用 DB transaction + row lock 實作。統一鎖順序：conversation → candidate capacity row（多位時固定 staff id 順序）。在同一交易內重查案件狀態、使用者資格與容量後更新。Redis lock 只能優化競爭，不能是正確性唯一依據。

沒有 eligible agent 時留在 human_queue 並顯示真正的等待/離線狀態。只有 supervisor 的強制轉派可超容量，需明確確認與 audit reason；不能用一般自動分派悄悄超額。

---

## 09. 訊息、事件與一致性

### 09.1 訊息模型

Message 支援 `text`、`attachment`、`system`；作者 `visitor / staff / ai / system`；可見性 `public / internal`。欄位詳見資料字典。DB 訊息 append-only；修改/刪除使用 edit/redaction 紀錄及 tombstone，不直接改寫歷史而無痕跡。

同一 conversation 內的 `message_seq` 由鎖定 conversation row 後遞增取得，不以 UUID/時間戳排序。JavaScript 對 bigint 使用十進位字串，不轉 Number。

### 09.2 冪等

瀏覽器/第三方送訊息必须帶 `client_message_id`；HTTP 寫入帶 `Idempotency-Key`。scope 至少包含 workspace + principal/session/client + method + route。相同 key/body 重試回原有結果；同 key 不同 body 回 `409 IDEMPOTENCY_CONFLICT`。

訊息另有長期唯一約束；即使 24 小時 HTTP idempotency cache 到期，也不能建立相同來源的重複訊息。第三方有 external_message_id/event_id 時以 connector + external id 去重。

### 09.3 人工接手與 AI 發布競態

AI run 捕捉 `answer_epoch`、`latest_customer_message_id`、`knowledge_generation`、`configuration_version` 與 task lease token。模型呼叫在交易外執行。

在發布前開一個短交易，鎖定 conversation 與 ai_run，確認：run 尚有效、task lease 屬於自己、handling_mode=ai、status=open、epoch/最新會員訊息/知識 generation/配置版本均一致。全部通過才能插入 AI message、更新 run=published、建立事件。

人工接手/轉人工、新的會員輸入、對話結案、知識撤下/發布或重要 AI 設定更動，必須廢止不再有效的 run。若人工接手先提交，AI 不得發出新公開答案；若 AI 先提交，該答案算接手之前已發布，不能宣稱可撤回已送達的網路資料。

人工內部備註或 read marker 不應單獨讓 AI 重新生成。對外 token 級串流預設不開啟，避免尚未檢查的內容已經被使用者看見。

### 09.4 Transactional Outbox 與重播

在同一個 DB transaction 保存 message/state + durable event + async task。成功提交後 HTTP 可回 accepted；queue enqueue 失敗不回滾已保存訊息，也不能造成客戶端誤以為沒有發送。

Dispatcher 以 lease 取出事件/任務，執行推送、webhook 或 queue 投遞。交付語意為 at-least-once，不宣稱網路 exactly-once；consumer 以 event_id 去重。`afterCommit()` 可用，但不等於完整 outbox，不能省略故障修復。

public/staff 有不同 projection 及各自 `event_seq`。內部備註不得以「先廣播完整資料、前端自己藏」處理。HTTP 補事件依單一對話與 audience cursor 查詢；保留 7 天，過期回 `410 CURSOR_EXPIRED` 並提供 snapshot 方式。

---

## 10. 知識庫、文件與 RAG

### 10.1 來源與發布

首版輸入：FAQ（問、答、同義問法）、Markdown、TXT、文字型 PDF。PDF 僅抽出文字，不承諾正確解析所有表格；掃描件顯示 `OCR_REQUIRED`，OCR 屬 P3。網站匯入只允許人工指定的公開頁面，嚴格 SSRF/大小/頁數限制，完整爬站/排程同步列 P2。

每份文件有 immutable version：`draft → indexing → ready → published → archived`，失敗為 `failed` 可重試。編輯產生新版本，舊發布版繼續服務；新版本完整建立 chunks/embeddings 後才能原子切換。撤下立即影響新檢索，舊 run 必須重新檢查 generation。

核准範圍：workspace、brand、指定 inbox、語言、適用期間。知識可見性 `external_answerable` 與 `staff_only` 分開；外部 AI 一律排除 staff_only，不是只把來源連結藏起來。對外 Help Center 公開文章是另外的發布 flag，external_answerable 不代表原文件可公開下載。

### 10.2 Pipeline

`原始檔 → 隔離掃描 → 擷取 → 正規化 → 結構切段 → Embedding → 索引檢查 → 待發布/發布`。

預設 chunk 目標 600 tokens、overlap 80，依標題/FAQ/段落優先保留上下文；數字是起點，對中文 tokenizer 實測後調整。FAQ 問答不要拆開；條款、例外、日期、表格標題與來源定位需隨 chunk 保留。

每個 chunk 保存文件版本、chunk index、標題路徑、page/offset、content hash、語言、有效期、可見性及 embedding profile。內容不經人工發布不得成為正式外部回答來源。

### 10.3 查詢流程

先完成授權與資料範圍過濾，再做問題正規化；對精確型號/錯誤碼優先 lexical，語意查詢使用向量。起始候選 vector 20 + lexical 20，以 RRF 合併去重，取 8；可配置 rerank 後取 5，再依模型 context budget 截斷。

中文 lexical 首版至少包含可編輯 FAQ keywords、標題/別名/錯誤碼的精確或部分比對；可用 pg_trgm 輔助。不要宣稱 PostgreSQL 預設全文檢索已解決中文斷詞，需繁體中文評測驗收 [R16]。純向量、混合及 rerank 的效果以固定測試集比較，不憑展示案例選擇。

所有查詢必須同時限制 workspace、brand/inbox knowledge binding、外部可見性、published version、有效時間與 embedding profile。ANN 索引的過濾可能減少候選數，需要評估 iterative scan/預選/分區或回退精確檢索；不能看到有 WHERE 就假設召回數一定足夠 [R06]。

### 10.4 Embedding 模型管理

Chat model、Embedding model、Reranker 是三種獨立設定。更換 Chat model 不重建向量；更換 Embedding 模型、維度、正規化或 task type 建立新 profile 並全量重建。即使同維度，不同模型也不混搜。

資料表允許多 profile 共存，只有核准 active profile 可被新查詢使用。新索引覆蓋率與評測通過後切換，舊 profile 保留回滾期。pgvector HNSW 的 `vector` 索引有 2,000 維限制、`halfvec` 可到 4,000 維；不要對 3,072 維資料直接建立不相容的 vector HNSW。具體表/索引策略見資料字典 [R06]。

### 10.5 回覆與來源

答案須基於取回的核准來源，沒有足夠來源則說明需要人工。模型輸出可以包含 `[S1]` 類引用，但後端必須確認 reference id 屬於此次檢索結果；不接受模型捏造 URL。

一般招呼可用核准固定模板，不必勉強附文件。退款規則、金額、時效、資格等規則型回答必須有可驗證依據。任何無法證實的「信心分數」不得直接當作 0.8 以上就保證正確；retrieval/rerank score 必須按 profile 評測校準。

首版供應商拒答、截斷、無來源、JSON schema 不符或來源撤下，最多一次受控重試/重新檢索，仍失敗轉人工。不能從任意 provider 失敗就自動把同一會員資料送給另一家。

---

## 11. AI 接待、供應商抽象與工具

完整規格見 `AI_ADAPTERS.md`。

### 11.1 產品模式

每個 inbox 可選 `disabled`、`assist_only`、`auto_reply`。Disabled 不呼叫模型；assist_only 只對人工產生草稿；auto_reply 僅在對話處於 AI 接待狀態自動公開回覆。設全 workspace、單品牌、單收件匣 kill switch。

使用 Laravel AI SDK 作第一個實作而不是業務契約本身；官方文件有多供應商文字、embedding、rerank 及自訂 endpoint 支援，但功能依供應商不同 [R02]。

供應商連線必須區分：
- OpenAI Responses 原生格式。
- OpenAI Chat Completions 相容格式（本地或第三方 gateway 可能只支援這個）。
- Anthropic Messages 原生格式。
- Gemini GenerateContent 原生格式。

不要只換 base URL 就宣稱四者完全相容。模型名稱不寫死，能力矩陣按 connection + model + protocol 驗證。新官方 API 另加 adapter，不替換已有契約。

### 11.2 工作流程與降級

同一對話收新訊息可 debounce 800ms 合併思考，但每則原文獨立保存。先查 deterministic handoff（明確要求人工/禁用/超額），再檢索與生成。最多一次自動澄清後仍不確定轉人工，不讓會員陷入重複問題。

AI 回覆從原始會員訊息接受起算預設最多 90 秒，包含 queue 等待。超過期限即標記 run 失效並交人工，不等 AI worker 自己從永遠卡住的狀態醒來。外部模型逾時、worker 掛掉、Redis 故障時，由獨立 DB watchdog 处理。

### 11.3 訂單/會員/CRM 工具

一般規則走知識庫；「我的訂單/餘額/處理進度」走有授權的業務 API。首版工具只允許 readonly，例如 `get_my_order_status`、`get_my_service_case`；未驗證的 visitor 不可使用。

工具的 contact/user/tenant 識別由可信 ActorContext 注入，不允許模型提供另一個 user_id。模型只能提出允許的查詢參數，後端驗證 ownership、schema、rate limit、timeout、欄位遮罩。禁止模型任意呼叫 URL、SQL、shell，或將工具結果內的文字當新指令。

會更動帳務、退款、出金、會員權限的操作不是首版範圍。未來要加入時，必須有獨立 human approval、短效確認、idempotency 及完整審計，不因 LLM 說「客戶同意」就執行。

---

## 12. 其他平台與系統串接

### 12.1 三層分離

Channel Adapter：LINE、Telegram、Email、網站等訊息收送。

Business Integration：會員/订单/CRM/ERP API，只處理經授權的資料與工具。

Automation Integration：Webhook、n8n/Make 類自動化、內部通知；只依事件與權限行動，不直接改 DB。

### 12.2 Channel Adapter 契約

至少包含 `verifyInbound`、`normalizeInbound`、`sendOutbound`、`fetchAttachment`、`capabilities`。Inbound 正規化成同一套 Contact/Conversation/Message；保留原始 external ids 與必要 metadata，原始 payload 需加密且短期留存。

能力欄位包括文字/附件/引用/已讀/輸入中/編輯/刪除/模板/主動發送/外部冪等。UI 依能力顯示，不支援的操作不顯示假成功。內部備註永不轉成外部訊息。

| 渠道 | 階段 | 實作/驗收注意 |
|---|---|---|
| Web / iframe / hosted page | M1 | 可完整控制身分、訊息、已讀、重連 |
| 通用渠道 API/Webhook | M3 | 服務端 token、HMAC、持久化、去重、重試與測試工具 |
| LINE Messaging API | P2 | 官方 webhook 簽章、event id 去重、重送/亂序、回覆/主動發送差異、附件、取消訊息；上線前檢查最新規則 [R13] |
| Telegram Bot API | P2 | secret token/webhook、update_id 去重、chat/user/thread mapping、bot 能力與權限限制 [R15] |
| Email | P2 | SMTP/供應商 adapter + inbound provider/IMAP；Message-ID/References threading、去重、auto-reply loop、附件掃描、垃圾信；不可只用主旨配對 |
| WhatsApp / Messenger / Instagram | P3 | 官方企業/API 審核、授權、模板/回覆限制、送達回執、簽章；不得假定一般帳號即可接入，具體政策在實作前重新核對 |
| Slack / Teams / Discord | P3 | 優先當內部通知/升級目的地；要作客服渠道另驗收會員與 thread 權限 |
| 原生 App / Capacitor | P2 | 安全 session bridge、push token、深連結、裝置登出/撤銷；不能沿用瀏覽器 null origin 放行 |

每個第三方 connector 有 enabled、credentials、capabilities、last_success、health、rate-limit、circuit state、raw retention 與 retry policy。外部回呼缺失/順序錯亂時，不把 delivered/read 狀態倒退。

### 12.3 一般 Server-to-Server API

提供 contact upsert、建立對話、送訊息、查狀態、申請轉人工、建立/更新知識版本、查詢事件等 scopes。API token 至少 256-bit 隨機值、只顯示一次、DB 存 hash，可按 IP/expiry/brand/inbox 限縮；所有 API 使用 HTTPS。

會員端的 API key 永遠不得放在 JS SDK。簡單 integration token 不能當作 workspace owner；上下游 contact 對應受 connector issuer 約束。

### 12.4 出站 Webhook

事件包括 `conversation.created`、`conversation.handoff_requested`、`conversation.assigned`、`conversation.resolved`、`conversation.reopened`、`message.created`、`message.delivery_updated`、`knowledge.published`、`ai.run.failed`。

Header：`X-Yacs-Event-Id`、`X-Yacs-Timestamp`、`X-Yacs-Key-Id`、`X-Yacs-Signature`。簽章原文為 `timestamp + "." + raw_body`，HMAC-SHA256 hex，header 形式 `v1=<hex>`；receiver 以 constant-time 比較，接受時間窗預設 ±300 秒並以 event_id 去重。

投遞需 durable outbox，預設重試 30s/2m/10m/1h/6h/24h，使用 jitter，最多 10 次；401/403 應告警暫停，429 尊重 Retry-After。每次重送重新產生 timestamp/signature、保持 event_id/body 語意不變。手動重送需 audit。

URL 只能 HTTPS，預設禁用 redirects，DNS/IP/port 檢查及網路 egress policy 防 SSRF。需要內網 connector 時由 platform operator 以特定 hostname/port 明確批准，不能讓一般租戶勾選「允許所有內網」 [R17]。

### 12.5 身分與外部帳號連結

Staff SSO 預留 OIDC，SAML 後續需求再加。外部 LINE/Telegram 身分與網站會員連結需要可驗證 linking flow；Email/姓名相同不足以合併。跨品牌預設完全不合併。MCP 如未來開放，仍經同一個 ToolPolicy/ActorContext，不能繞過既有授權。

---

## 13. API、事件與錯誤規約

首版 API 詳見 `API_CONTRACT.md` 與 OpenAPI。主要命名空間：

- `/api/v1/widget/*`：訪客，短期 bearer token。
- `/api/v1/workspaces/{workspace_id}/*`：後台，session + CSRF + workspace policy。
- `/api/v1/integrations/*`：服務端，scoped bearer token。
- `/api/v1/hooks/{connector_key}`：經驗證 webhook，無 staff session。

回應以 `{data, meta}`，錯誤以 `{error:{code,message,details,request_id}}`。一般 payload 最大 64KiB；文字訊息最大 8,000 Unicode characters；附件走專用上傳流程，不塞 base64。cursor pagination 預設 50、上限100。時間 RFC3339 UTC，金額 decimal 字串+currency，bigint/seq 使用十進位字串。

不可直接序列化 Eloquent model。PublicMessage、StaffMessage、ContactPublic、ContactStaff 分開 resource；DTO 調整需 contract test。

### 13.1 錯誤碼

`UNAUTHENTICATED(401)`、`FORBIDDEN(403)`、`NOT_FOUND(404)`、`VERSION_CONFLICT(409)`、`IDEMPOTENCY_CONFLICT(409)`、`CAPACITY_EXCEEDED(409)`、`INVALID_STATE(409)`、`IDENTITY_REPLAYED(409)`、`NEW_CONVERSATION_REQUIRED(409)`、`CURSOR_EXPIRED(410)`、`VALIDATION_FAILED(422)`、`CAPABILITY_UNSUPPORTED(422)`、`RATE_LIMITED(429)`、`TEMPORARILY_UNAVAILABLE(503)`。

未授權物件可用 404 降低存在性洩露，但同一類接口保持一致。AI 供應商錯誤只显示去敏感摘要與 internal error code，不透傳 key、prompt、請求headers。

---

## 14. 附件、檔案與內容安全

首版對話附件允許 PNG/JPEG/WebP/PDF/TXT；每檔10MiB、每訊息最多5個，可配置。知識匯入每檔20MiB，文字型 PDF 200頁上限作起點。SVG/HTML/可執行檔/壓縮檔預設禁止。

檔案需 workspace/contact/staff 範圍驗證；上傳宣告的 Content-Type 不可信，檢查 magic bytes、實際大小、解碼後像素數/壓縮炸彈與病毒掃描。狀態 `pending_upload → quarantined → clean | rejected`，未 clean 不可供會員/AI/第三方下載或引用。

使用私有 bucket/object key；短效簽名下載有效期60秒，生成前重查權限，移除/撤銷後不再簽發。圖片移除 EXIF，預覽採安全轉換；PDF 以下載或隔離檢視器顯示。遠端附件下載亦適用 SSRF、redirect、大小及 timeout 限制。

掃描服務故障時 fail closed：附件留待處理，文字訊息仍可使用；不能因為掃描失敗改成自動允許。

---

## 15. 安全、稽核、資料保留與 AI 防護

管理員/主管正式環境強制 MFA；登入限流、邀請/重設 token 單次短效。後台同站 cookie HttpOnly/Secure/SameSite、CSRF 保護；widget 跨站使用受限 bearer，不依赖第三方 cookie。Sanctum 的 SPA cookie 模式與 API token 是不同機制，不混為一談 [R05]。

所有可顯示文字 escaping；Markdown 僅白名單安全 render。附件/Knowledge/工具結果視為不可信資料，不能覆蓋 system policy。模型對話不直接包含 secret、內部備註或超出 actor 範圍的資料。

provider credentials / connector secrets 使用 secret_ref 或 envelope encryption，資料庫備份與解密金鑰分開保存；禁止原文匯出。日誌採 allowlist，不紀錄 Authorization、Cookie、JWT、refresh token、完整 prompt 或工具敏感結果；必要 debug 單獨加密、權限控管、短期到期。

預設保留提案：聊天與附件180天、稽核365天、去識別 AI 執行 metadata 90天、原始 webhook payload 7天、原始 provider debug 預設0天、realtime events 7天、一般 idempotency response24小時。這些是產品設定，不是法律合規結論；正式啟用前由資料負責人確認。

資料刪除必須涵蓋 DB、附件、向量、cache、評測複本與已匯出暫存；backup 依到期策略與 erasure tombstone 處理，還原後重放 tombstone。供應商端的保留不能因本地刪除就假設同步完成。

---

## 16. 佇列、部署與故障恢復

### 16.1 儲存與程序

正式環境至少分開 `app`、`reverb`、`horizon-core`、`horizon-ai`、`horizon-kb`、`scheduler/watchdog`。可以同機多程序/容器起步，不代表高可用。PostgreSQL、Redis、物件儲存不對公网開放。

Redis queue 專用 instance / 非 Cluster endpoint；Horizon 官方文件目前不相容 Redis Cluster，不能直接用 Cluster/某些 serverless Redis 當可替換選项 [R04]。queue instance 使用 `noeviction`、持久化與容量告警；cache 可另 instance 採適合的淘汰政策。只分 logical DB 不等於記憶體與故障隔離。

所有 queue payload 只帶 task/workspace/entity id 與小型 metadata，目標16KiB以下；文件、完整聊天、圖片/base64、巨大 ORM 關聯不得塞進 Redis。

### 16.2 逾時與資源隔離

以 `CONFIG_DEFAULTS.yaml` 為起始值。AI HTTP connect5s、單次回應45s、整個 run deadline90s；job timeout70s、Horizon supervisor timeout80s、queue connection retry_after120s。KB：job240s、supervisor270s、retry_after330s。各種 connection 的 retry_after 分開配置，不能只設單一90秒却跑4分鐘索引工作。

Laravel 要求 worker timeout 短於 retry_after；還要避免 SDK 自動重試疊加 job 重試造成請求放大 [R08]。AI run 最多2次 provider嘗試且受總deadline約束；常態不得 `tries=0` 無限重試。

Horizon 至少分 core、ai、kb、events/channels supervisor，core/dispatch/watchdog 保留資源，不能全部 autoscale 到 AI。傳送失敗的渠道處理使用獨立重試策略。

### 16.3 Durable 任務與 watchdog

`async_tasks` 保存 pending/queued/running/succeeded/failed/cancelled 與 lease。dispatcher 可以重投 lost/stale 任務；worker 原子 claim 並驗證 fencing token，完成後標記。Redis 清空/失效後可由 DB 找出需要恢復的任務，但恢復流程必須演練。

獨立 watchdog 每10秒檢查已超 deadline 的 AI run 與無人處理的 queued/running 任務；不依賴同一條 AI queue 才能把會員轉人工。DB 還活著且 app可服務時，Redis/Reverb 故障可維持 HTTP 訊息保存、人工輪詢及稍後推送恢復。

### 16.4 發佈與備份

鎖定 Composer/NPM lock、Docker digest、migration版本。採 expand/contract schema migration；先 staging、備份驗證、相容發佈、重啟長駐worker/reverb、觀察後才移除舊欄位。不在正式環境跑 `migrate:fresh` / 無確認清空資料。

DB 做持續 WAL/PITR + 每日base備份，附件做版本/備份，解密key另行備份。起始目標 RPO≤15分鐘/RTO≤2小時只在備份與還原演練證實後標記達標；單純每日 pg_dump 不能宣稱15分鐘RPO。

---

## 17. 可觀測性、指標與報表定義

每個 request、conversation、message、ai_run、async_task、webhook delivery 可用 request_id/trace_id 關聯；不以 raw prompt 當 trace。

指標：HTTP p95/error、訊息持久化耗時、queue oldest age、AI首次嘗試等待/生成/總時間、手動接單等待、handoff原因、未分派量、向量檢索時間與候選數、embedding失敗率、outbox滯留、重複抑制、delivery_unknown、Redis memory/eviction、PG connection/lock、Reverb connections/reconnect。

首回覆時間分 human 與 AI；結案後重開不覆寫第一次回覆事件；AI containment 是觀測視窗內未轉人工，不等於問題一定解決。CSAT 只對有效問卷回覆計算，報表顯示分母/回收率。模型成本分 confirmed usage 與 estimated usage，價格配置有幣別與生效日，不硬編碼網路價格。

告警起點：core queue oldest>10秒、AI期限超過占比>5%、outbox超60秒、任意Redis eviction>0、DB容量>80%、備份過期、跨租戶拒絕異常增加。門檻依實際流量調整。

---

## 18. 效能與品質驗收

這些是可重現驗收目標，不代表已實測達標。測試必須記錄版本、硬體、worker數、DB索引、資料量與模型/模擬器設定。

**參考環境**：8 vCPU / 16GiB RAM應用+worker，獨立4 vCPU / 16GiB PostgreSQL，獨立Redis；模型使用可控fake服務測試平台，真實模型另測。目標不是要求第一天租這個規格，而是避免沒有環境基準的速度承諾。

**資料集**：2 workspace、各2 brand、總10 inbox、30座席、100萬訊息、5萬knowledge chunks；用惡意同名/同ID關聯資料測隔離。

**負載目標**：1,000個連線訪客、30個座席、10則新訊息/秒、10個同時AI工作；30分鐘穩態加2倍5分鐘突發。非模型API p95<500ms、同區域已持久化訊息推送p95<1s、zero跨租戶洩漏/已接受訊息遺失/重複final publication。達不到就記錄瓶頸與調整，不能只降低測試量宣布成功。

**知識評測**：至少150題本地真實客服題作第一版起點，含正常/例外/繁簡體/拼字/無答案/越權/提示注入/過期資料。所有高風險與越權案例必須阻擋；普通答案依人工標註正確、來源支持、應否轉人工分開評分。正確率門檻由資料負責人批准，不用 LLM 自評取代人工標註。

---

## 19. 首版完成定義

M0–M3 的必測項目全部通過；真實PG/Redis並發測試，不以SQLite取代；至少一個真實聊天供應商與一個embedding profile完成驗證，其他宣稱支援的協定需有fixture contract test，未帶key實測者明確標記「未完成實機驗證」。

三套畫面可操作：前台可問答/轉人工、客服可處理/結案、運營可設定/發布/授權。JS SDK有可用示範網站與宿主後端身分交換說明。OpenAPI與實際route、request/response一致，所有secret不出現在前端。

交付部署/升級/回滾/備份/還原/事故手冊、權限與威脅測試、固定評測集、效能結果、套件license清單和已知限制。沒有完成真實第三方平台驗證，不得在UI顯示「已支援/已連線」。

**最後決策：先把人工客服當成獨立可用的產品，再接入受控AI；AI只是可替換的接待角色，不是權限與狀態的決策中心。**

---

## 20. 外部依據與限制

完整官方來源與查核日期見 `REFERENCES.md`。技術事實引用以 [Rxx] 標記；其他 MUST、流程、預設值與里程碑均為本案設計決策。

Laravel AI SDK可統一多供應商，但不能由此推論每個模型功能相同。OpenAI PHP客戶端列於官方社群庫而非官方PHP SDK；Anthropic已有官方PHP SDK但本次查核文件仍註明beta；Google目前官方GenAI語言清單未列PHP，因此本案不要求三家都有官方PHP SDK才可接入 [R02][R09][R10][R11]。

套件官方/第一方不等於永遠沒有bug。實作者必須在M0記錄確切版本、release狀態、授權、依賴及已知相容性；SDK/供應商API變動只限adapter層處理，不擴散到conversation domain。
