# HTTP、即時事件與整合契約

## 1. 契約範圍

`contracts/openapi.yaml` 定義首版核心流程、主要設定資源與通用整合的OpenAPI 3.1契約。它不是已運行服務的自動匯出；實作時需做route/schema contract test，將畫面所需的其餘CRUD（巨集、標籤、報表明細等）依本文件補齊，再產生正式全量OpenAPI。不能對外宣稱尚未定義/實作的第三方平台已可使用。

所有path以 `/api/v1` 為主，OpenAPI `servers` 使用佔位HTTPS域名。UUID為字串，version/seq是十進位字串；datetime UTC。非公開key不出URL。每個資源response均以專用DTO控制欄位，不直接回ORM。

## 2. 三種認證

**Staff**：`/ops`與`/agent`同站部署，Laravel session + CSRF（Sanctum/Fortify相容流程）。先GET `/sanctum/csrf-cookie`，再login；MFA challenge完成後才能取得有效session。OpenAPI中cookie `supportdesk_session`為本案配置名稱。所有staff寫入同時需要session及`X-XSRF-TOKEN`；GET不要求CSRF。API操作仍需workspace/membership/Policy。

**Visitor**：公開inbox_key bootstrap匿名session，或以宿主後端簽發的identity JWT交換。Authorization Bearer token只能存取自己brand/inbox/contact/session的資料。Refresh不以staff cookie運作。

**Integration**：後台建立的scoped token，用於服務端。所有資源scope由token與server查出的connector mapping決定，不能利用body中的workspace_id擴大權限。

通用入站Webhook不是staff/integration session，用raw body HMAC + timestamp + event id驗證。LINE/Telegram等之後採各平台的驗證方式，不混用本案HMAC規格。

## 3. 回應與冪等

成功：`{ "data": <resource>, "meta": { "request_id": "..." } }`。

列表：`{ "data": [], "meta": { "next_cursor": null, "has_more": false, "request_id": "..." } }`。

錯誤：

```json
{
  "error": {
    "code": "VERSION_CONFLICT",
    "message": "對話已由其他操作更新，請重新載入。",
    "details": { "current_version": "12" },
    "request_id": "req_example"
  }
}
```

需要副作用的建立/傳送/狀態操作必須帶Idempotency-Key（UUID或至少16字元隨機值）；讀取、typing、read marker、presence用天然冪等的PUT/更新上界，可不要求key。Login/refresh亦有各自單次token與replay規則，不盲目使用包含secret的普通response cache。

相同principal+method+route+key的body hash相同，回傳原結果；若body不同，409。原始回應已包含短期token者，僅可在極短期加密保存以支援可靠重試，或讓caller重新取得新一次性JWT，禁止token明文進cache/log。

## 4. 首版端點群組

| 群組 | 主要路徑 | 行為 |
|---|---|---|
| Auth | `/auth/login`, `/auth/mfa/verify`, `/auth/logout`, `/me` | session、MFA、可用workspace |
| Widget | `/widget/bootstrap`, `/widget/identity`, `/widget/token/refresh`, `/widget/logout` | 初始化、驗證、輪替、撤銷 |
| Visitor conversation | `/widget/conversations`, `/{id}`, `/{id}/messages`, `/{id}/events`, `/{id}/snapshot` | 限定本人對話與public DTO |
| Visitor actions | `/{id}/handoff`, `/{id}/read`, `/{id}/resolve`, `/{id}/csat` | 訪客要求人工、已讀、確認解決、評分 |
| Staff conversation | `/workspaces/{w}/conversations`及`/{id}/messages|events|snapshot` | 範圍內列表/訊息/持久事件 |
| Staff actions | `/{id}/claim|assign|release|resolve|reopen|snooze|resume-ai` | 版本/權限/容量/狀態交易 |
| Staff AI assist | `/{id}/ai-drafts`, `/ai-runs/{id}` | 草稿不自動公開 |
| Files | `/widget/files/uploads`, `/workspaces/{w}/files/uploads`及complete/download | 私有檔案、掃描、授權下載 |
| Ops resource | `/workspaces/{w}/brands|inboxes|members|teams|roles` | 型別化資源設定 |
| Knowledge | `/knowledge-bases`、`/knowledge-documents`、`/knowledge-versions`、`/knowledge-search` | 版本索引、發布與測試 |
| AI | `/provider-connections`、`/ai-models`、`/embedding-profiles`、`/ai-profiles` | 協定/模型/能力/獨立profile |
| Integration | `/api-clients`、`/webhook-endpoints`、`/webhook-deliveries` | credentials僅寫入、測試/重送/審計 |
| Server API | `/integrations/contacts/upsert`、`/integrations/conversations`、`/.../messages|handoff` | Scoped機器操作 |
| Generic webhook | `/hooks/{connector_key}` | 驗章→去重→DB保存→2xx→非同步處理 |
| Reports/audit | `/reports/summary`、`/audit-logs`、`/exports`、`/async-tasks/{id}` | 授權範圍與非同步產檔 |

除了表中已展開的核心契約，M1–M3尚須逐步補齊：邀請接受/密碼重設、MFA enrollment/recovery、staff role bindings、營業時間/假日、巨集與標籤CRUD、通知偏好、知識測試集CRUD/執行明細、CSAT報表、資料刪除審批。各頁定義見SPEC，新增API先加OpenAPI再實作。這些是已知規格展開工作，不是未經宣告的完成項目。

## 5. 訊息與狀態操作規則

Visitor create message不提供author_type/contact_id/visibility/workspace_id，全部由session決定，永遠public。Staff create message需visibility(public/internal)，public必須human模式、本人assignee或具assist_other；internal需note權限，可對自己可讀案件留言。

Staff變更狀態提供 `expected_version`，不符回409含current_version。不能用PATCH conversation任意寫status/assignee繞過action。

已resolved案件：72小時內visitor新訊息可交易重開；超過視窗回`NEW_CONVERSATION_REQUIRED`，SDK先create related conversation再送同一draft/client_message_id。內部staff回覆需先明確reopen。Read marker只能往前，超出可見最新訊息回422。

### 典型member聊天順序

1. 宿主backend驗證會員 → 簽短期identity JWT。
2. SDK bootstrap（拿到public config及匿名session，可直接identity取代匿名流程）。
3. SDK identity交換 → 撤銷/捨棄舊匿名session，安裝新member token，不自動合併歷史。
4. 建立或載入會話 → 訂閱Reverb private channel → POST message → 取得持久化Message。
5. 接event，必要時GET events/snapshot補資料；手動requestHuman優先於AI。
6. 宿主logout呼叫SDK logout；後端撤銷訪客session，不只清除姓名。

## 6. Reverb認證、受眾與回補

使用Laravel Echo/相容Pusher client連Reverb；Reverb app key是公開識別，app secret只在服務端。自訂auth endpoints：`/widget/broadcasting/auth`（visitor bearer）與 `/workspaces/{w}/broadcasting/auth`（staff session），body為socket_id/channel_name；response採協定要求的auth格式，不套data envelope。

建議channel：

- `private-visitor.{visitor_session_id}.conversation.{conversation_id}`。
- `private-staff.{staff_session_id}.{membership_id}`，每條事件仍帶conversation_id。

Auth需驗證session活躍、角色scope及conversation歸屬；服務端fanout只針對**目前仍有效且有權限的session**。不能把所有staff丟同一公共workspace channel，再靠前端藏資料；也不能認為訂閱時auth過一次，撤銷後就永遠安全。

每次publish依有效session/scope fanout，快取授權最多60秒、撤銷時主動失效。Socket無法立即斷開時，也不得再送新資料到被撤銷的session channel。

### Durable event envelope

```json
{
  "schema_version": "1.0",
  "event_id": "11111111-1111-4111-8111-111111111111",
  "type": "conversation.updated",
  "workspace_id": "22222222-2222-4222-8222-222222222222",
  "conversation_id": "33333333-3333-4333-8333-333333333333",
  "event_seq": "42",
  "occurred_at": "2026-09-26T00:00:00Z",
  "data": {
    "conversation": {
      "id": "33333333-3333-4333-8333-333333333333",
      "status": "open",
      "handling_mode": "human_queue",
      "version": "3",
      "last_event_seq": "42"
    }
  }
}
```

實際event data依type採schema定義；message.created使用完整PublicMessage或StaffMessage投影。Public event不得包含staff audit/內部備註。Public和staff的event_seq各自單調增加；型別schema見 `contracts/realtime-event.schema.json`。

重連算法：保留目前conversation最後完整套用的event_seq；GET events(after_seq)，逐序/去重套用；出現缺口再補；410則GET snapshot重建。Snapshot讀取transaction內一致的conversation/最近message頁/last_event_seq，避免抓到事件較新但message較舊的混合狀態。

Typing/heartbeat是ephemeral，沒有durable seq、不補播、不影響message順序，不應寫入audit每個keystroke。前台可先呈現自己HTTP回應的訊息，之後WS用message_id/client_message_id合併，不能顯示兩份。

## 7. 通用Webhook

Inbound event schema：event_id、type、occurred_at、external_thread_id、external_contact(subject/name)、message(external_message_id/text/attachment_refs)。原始外部附件URL在下載前過SSRF檢查；不直接塞前台img src。

驗證raw body（未重新JSON編碼前）的 `HMAC(secret, timestamp + '.' + raw_body)`，比對v1 signature；timestamp窗±300秒，event_id DB唯一，header event id必須與body event_id一致。驗證與持久化完成後回202 accepted/重複回200；簽章錯誤401，過期401，DB不可用503讓sender重送。

Outbound同一事件可能多次投遞；receiver event_id去重，事件不保證跨conversation全域順序。對外只傳核准欄位，不把provider keys/visitor tokens/內部備註附在message.created裡。

送出第三方平台後HTTP timeout屬`delivery_unknown`：可能已收到。若平台無冪等key/查詢API，不可自動多次重送同一答案；標示待核對，供客服處理，並保留manual resend audit。

## 8. API限流起點

匿名bootstrap：每IP/每inbox 30次/10分鐘；會員identity每IP/inbox 60次/分鐘；訪客訊息每session 20則/分鐘、burst5；API client100請求/秒作上限起點；客服依操作設較高限額。IP共同出口/NAT需監控，不能把公共IP限額當唯一防護。rate limit回429帶Retry-After。

附件、匯出、索引與AI probe另外限制，不可用大量probe燒預算。任何管理員設定值都需服務端上下限，不能輸入無限併發/不限制檔案大小。
