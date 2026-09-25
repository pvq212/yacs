# 驗收測試規格

版本1.0.0。以下為要求，**不是已通過的測試結果**。機器可讀版本：`acceptance-tests.yaml`。

## 測試環境與方法

後端使用Pest/PHPUnit、PostgreSQL與Redis真實服務；前端使用Vitest/Playwright。並發測試用獨立DB connection與barrier控制順序，不以大量sleep碰運氣。Provider/渠道使用可控fake HTTP server、固定fixtures與fake clock；外部實機測試獨立標記。

所有blocking案例必須通過；critical主要是隔離、資料完整性與競態。每個案例回報test名稱、命令、結果與證據，不把本YAML當成測試程式。

## 安全與隔離

### SEC-001 · 跨workspace物件讀取（M0）
**Given：** A/B workspace各有相同名稱的品牌與對話。  
**When：** A token改URL/body查B conversation。  
**Then：** 404/403；response/log/WS/統計無B內容。

### SEC-002 · 同workspace跨品牌隔離（M0）
**Given：** 客服只獲brand A inbox授權。  
**When：** 搜尋/列表/報表/附件改為brand B。  
**Then：** 無原文、姓名、count或附件資訊洩漏。

### SEC-003 · 複合外鍵（M0）
**Given：** A conversation與B contact存在。  
**When：** 直接以runtime DB連線插入跨workspace parent id。  
**Then：** DB FK拒絕，不只Controller拒絕。

### SEC-004 · Agent不是Supervisor（M0）
**Given：** Agent可看自己案件與未分派佇列。  
**When：** 查同團隊另一客服已接案件。  
**Then：** 依預設拒絕；主管指定scope可查看。

### SEC-005 · 角色不可自我提權（M0）
**Given：** Operations Admin缺Owner/特定grant。  
**When：** 建立角色並給自己Owner或超出scope權限。  
**Then：** 403並audit，未改資料。

### SEC-006 · 長駐context不殘留（M0）
**Given：** 同一Octane worker依序或交錯服務A/B workspace。  
**When：** 使用不同AI credentials、locale與Policy scope。  
**Then：** 不共用或殘留另一workspace的key/context。

### SEC-007 · 內部備註永不外洩（M1）
**Given：** 客服新增internal note。  
**When：** 前台GET/WS/events/export webhook讀取同對話。  
**Then：** Public DTO與public channel完全無note或敏感metadata。

### SEC-008 · Socket授權（M1）
**Given：** Visitor知道另一conversation/session channel名稱。  
**When：** 呼叫broadcast auth或重用錯session資訊。  
**Then：** 拒絕；無資料推送。

### SEC-009 · 撤銷既有socket（M1）
**Given：** 使用者已有訂閱且client不配合logout。  
**When：** 後端停用staff/visitor或取消權限。  
**Then：** 60秒內不再收到新事件，不能靠只驗證subscribe。

### SEC-010 · CSRF（M1）
**Given：** Staff已有cookie。  
**When：** 跨來源表單/缺CSRF發送管理寫入。  
**Then：** 拒絕，合法同站CSRF流程可用。

### SEC-011 · Secret不可讀（M3）
**Given：** 設定provider key與webhook secret。  
**When：** 讀取API/HTML/日誌/錯誤/匯出/稽核。  
**Then：** 不含明文secret，僅masked/present。

### SEC-012 · SSRＦ全面覆蓋（M3）
**Given：** 可配置provider URL、webhook、知識URL、附件URL。  
**When：** 輸入localhost/RFC1918/metadata/IPv6/redirect/DNS rebinding案例。  
**Then：** 預設擋下；明確platform-approved host/port例外受控。


## 身分與SDK

### ID-001 · 偽造會員ID（M1）
**Given：** 未登入visitor。  
**When：** 只傳user_id/email或修改JWT claims。  
**Then：** 無法取得已驗證會員與其歷史。

### ID-002 · JWT完整驗證（M1）
**Given：** 合法issuer與簽章測試key。  
**When：** 測wrong alg/aud/iss/brand/inbox/expired/future iat/kid。  
**Then：** 全部拒絕；合法token可交換。

### ID-003 · 一次性JWT併發（M1）
**Given：** 同一jti存在兩個同時交換請求。  
**When：** 使用barrier同步送出。  
**Then：** 最多一次消耗；合法相同冪等重試回同session，不產生兩個會員session。

### ID-004 · Refresh rotation/reuse（M1）
**Given：** 合法visitor refresh token。  
**When：** 旋轉後再次使用舊token。  
**Then：** 偵測reuse並撤銷該family，不接受已失效token。

### ID-005 · 宿主logout（M1）
**Given：** 會員A有草稿與socket。  
**When：** 宿主logout後同瀏覽器登入B。  
**Then：** B看不到A歷史/未讀/草稿，舊session不再收資料。

### ID-006 · 匿名合併（M1）
**Given：** 匿名曾交談，之後登入會員。  
**When：** 執行identify。  
**Then：** 預設不自動合併；顯式合併需兩方持有權與audit。

### ID-007 · postMessage隔離（M1）
**Given：** widget已handshake。  
**When：** 錯origin/source/nonce發message。  
**Then：** 忽略，不執行命令；正確origin可用。

### ID-008 · SPA重複初始化（M1）
**Given：** 網站多次切頁/呼叫init。  
**When：** 重複init/open/destroy再init。  
**Then：** 不多生iframe/socket/事件listener；destroy不冒充logout。

### ID-009 · 第三方cookie封鎖（M1）
**Given：** 瀏覽器封鎖第三方cookie。  
**When：** 匿名/會員bootstrap、發訊息、重整。  
**Then：** 受限token機制可用；無法persist匿名時顯示合理狀態。

### ID-010 · 前端自訂欄位提權（M1）
**Given：** attributes有visitor-editable設定。  
**When：** 傳VIP、balance、verified=true或別人contact_id。  
**Then：** 拒絕或忽略並依契約回錯，不成為可信業務事實。


## 訊息與狀態

### MSG-001 · 訊息重送（M1）
**Given：** 一次POST已commit但response遺失。  
**When：** 相同client_message_id/key/body重送10次。  
**Then：** 只一筆message，回原ID與seq，UI只一份。

### MSG-002 · 同key不同body（M1）
**Given：** 既有idempotency record。  
**When：** 同key換文字/附件再送。  
**Then：** 409，不污染既有內容。

### MSG-003 · HTTP冪等到期（M1）
**Given：** message已存在，HTTP response cache清除。  
**When：** 重送原source/client_message_id。  
**Then：** 長期unique仍抑制重複。

### MSG-004 · 真正順序（M1）
**Given：** 兩條獨立PG連線並發寫同對話。  
**When：** 用barrier控制commit/競爭。  
**Then：** message_seq單調唯一，不依UUID/created_at推序。

### MSG-005 · 雙客服接單（M1）
**Given：** 一個human_queue案件。  
**When：** 兩客服同時claim同version。  
**Then：** 一個成功，一個409；唯一assignee與一次容量占用。

### MSG-006 · 容量競爭（M1）
**Given：** 某客服只剩一格容量。  
**When：** 兩個案件同時自動分派給他。  
**Then：** 最多一件成功，另一留queue或分別人。

### MSG-007 · 無人在線（M1）
**Given：** 沒有eligible available agent。  
**When：** 會員要求人工。  
**Then：** human_queue/離線文案，不假裝已接通，不丟訊息。

### MSG-008 · 過期人工操作（M1）
**Given：** 客服草稿仍在舊version。  
**When：** 另一人先轉派，原人再公開送出。  
**Then：** 409並保存草稿，不越權回覆。

### MSG-009 · 等待會員與結案（M1）
**Given：** 人工回覆並waiting_customer後接到新會員訊息。  
**When：** 回覆→結案→72小時內新訊息。  
**Then：** 正確open/human→resolved→human_queue重開，報表周期不混淆。

### MSG-010 · 過重開視窗（M1）
**Given：** 已結案超過72小時。  
**When：** 往舊conversation POST會員訊息。  
**Then：** 409 NEW_CONVERSATION_REQUIRED；SDK新建related會話，原draft不丟失。

### MSG-011 · Snooze容量（M1）
**Given：** 人工snoozed，容量已釋出且可能被占滿。  
**When：** wake_at到達。  
**Then：** 重查資格/容量，適當回human或human_queue，不超賣。

### MSG-012 · 已讀單調（M1）
**Given：** last_read_seq已較新。  
**When：** 送舊seq或超過可見最新seq。  
**Then：** 舊seq不倒退，越界422，內部note不洩漏。

### MSG-013 · WS缺口/重播（M1）
**Given：** 前端已套用event41，漏42收到43。  
**When：** 重連補after41，或游標過期。  
**Then：** 正確套用42/43；expired410+一致snapshot，不漏訊息。

### MSG-014 · Bigint安全（M1）
**Given：** seed seq大於2^53。  
**When：** API→SDK→排序/補播。  
**Then：** 全程字串/BigInt安全，沒有精度丟失。


## 前台與工作台

### UI-001 · 行動版與IME（M1）
**Given：** 窄螢幕/軟鍵盤/中文IME。  
**When：** 組字Enter、附件、泡泡開關。  
**Then：** 組字不誤送，輸入框可見，close focus回原元素。

### UI-002 · 無障礙（M1）
**Given：** 只用鍵盤/螢幕閱讀器/放大200%。  
**When：** 開泡泡、切對話、發訊息、轉人工。  
**Then：** 主要流程可完成，狀態非純色，live通知不洗版。

### UI-003 · 斷線待送（M1）
**Given：** 發訊息時斷網。  
**When：** 恢復連線後重試。  
**Then：** 保留draft與原client id，無假成功/重複。

### UI-004 · 多分頁通知（M1）
**Given：** 同staff開三個分頁。  
**When：** 收到同一事件。  
**Then：** 每頁列表正確，通知/聲音合理去重。


## 檔案

### FILE-001 · 上傳偽裝（M1）
**Given：** 檔名jpg但magic為HTML/執行檔。  
**When：** 上傳與complete。  
**Then：** 拒絕，不以客戶端Content-Type信任。

### FILE-002 · 掃描未完成/故障（M1）
**Given：** 檔案在quarantined。  
**When：** 附到訊息/給AI讀取/下載。  
**Then：** 禁止；掃描故障不fail open，文字聊天可用。

### FILE-003 · 下載權限（M1）
**Given：** 另一brand/visitor知道file id。  
**When：** 取signed URL或改message附件id。  
**Then：** 拒絕；已刪/撤銷後不發新URL。

### FILE-004 · PDF限制（M2）
**Given：** 掃描PDF、巨型PDF、損壞文件。  
**When：** 知識匯入。  
**Then：** 可理解OCR_REQUIRED/too_large/parse_failed，不假裝已索引。


## AI接待

### AI-001 · 模型可完全關閉（M2）
**Given：** 未提供任何provider key。  
**When：** 跑純人工M1流程。  
**Then：** 可用且不嘗試外部AI請求。

### AI-002 · 人工先提交（M2）
**Given：** AI fake provider阻塞到指定barrier。  
**When：** human claim/handoff先commit，再釋放AI。  
**Then：** 無新AI公開message；run stale/cancelled。

### AI-003 · AI先提交（M2）
**Given：** AI已原子publish。  
**When：** 人工接手。  
**Then：** 該答案作已發布歷史，之後不再自動回答，不聲稱可收回已送token。

### AI-004 · 新問題廢止舊答案（M2）
**Given：** AI針對訊息A生成中。  
**When：** 會員送B後AI完成A。  
**Then：** A的舊run不發布，新epoch可針對新語境處理。

### AI-005 · Queue重跑final（M2）
**Given：** AI已publish，task重投/worker crash重試。  
**When：** 重新執行相同run。  
**Then：** unique+狀態檢查，最多一筆final message。

### AI-006 · Worker卡住（M2）
**Given：** AI worker永不返回或被kill。  
**When：** deadline已到，watchdog獨立執行。  
**Then：** 10秒掃描周期後轉human_queue，舊lease不可再publish。

### AI-007 · Assist不是autopilot（M2）
**Given：** human接待案件。  
**When：** 客服生成AI draft。  
**Then：** 草稿僅staff可讀，不自動送客戶。

### AI-008 · 原生協定（M2）
**Given：** 四種protocol有正常/錯誤fixture。  
**When：** 執行adapter contract suite。  
**Then：** DTO一致，Anthropic/Gemini不硬套OpenAI格式。

### AI-009 · 能力不支援（M2）
**Given：** model capabilities無tools/json/embedding。  
**When：** 要求該功能。  
**Then：** CAPABILITY_UNSUPPORTED，非空成功或靜默忽略。

### AI-010 · 截斷/拒答（M2）
**Given：** 上游返回refusal/empty/max_tokens或stream中斷。  
**When：** 生成流程完成。  
**Then：** 不當完整成功，受控重試或交人工。

### AI-011 · 重試放大（M2）
**Given：** SDK/queue可設定retry。  
**When：** 429/500/timeout混合。  
**Then：** 每run不超2次provider嘗試，受90秒總deadline。

### AI-012 · Fallback資料政策（M2）
**Given：** 備援provider未獲workspace同意。  
**When：** 主provider故障。  
**Then：** 不外送資料給備援；交人工或只用已核准fallback。

### AI-013 · 成本未知與預算（M2）
**Given：** usage缺失或多個run同時剩餘預算不足。  
**When：** 生成/預留/結算。  
**Then：** unknown不記0，並發預留不超過設定，人工仍可用。

### AI-014 · 停用即生效（M2）
**Given：** AI尚生成中。  
**When：** 管理員kill switch或關鍵profile變動。  
**Then：** 新run不啟動，舊run發布前失效。

### AI-015 · Prompt injection（M2）
**Given：** 會員/文件/工具結果含跳過規則與索取secret指令。  
**When：** 檢索並生成。  
**Then：** 不超scope、不執行任意工具，不回secret。

### AI-016 · 工具查本人資料（M2）
**Given：** 已驗證會員A、存在B订单。  
**When：** 模型工具參數嘗試user_id=B或order屬B。  
**Then：** 後端ownership拒絕，ActorContext不由模型覆寫。


## 知識與檢索

### RAG-001 · Staff-only隔離（M2）
**Given：** 公開與staff_only文件內容近似。  
**When：** 外部auto retrieval/answer。  
**Then：** 排除staff_only，包括引用/日志/候選內容。

### RAG-002 · 草稿不生效（M2）
**Given：** 舊published版與新draft/indexing版。  
**When：** 外部提問。  
**Then：** 只用舊核准發布版。

### RAG-003 · 發布原子性（M2）
**Given：** 新version部分chunks失敗。  
**When：** 嘗試publish。  
**Then：** 拒絕；舊版仍可用，沒有半新半舊。

### RAG-004 · 撤下中生成（M2）
**Given：** AI檢索了將撤下文件。  
**When：** 先unpublish再讓AI返回。  
**Then：** generation不符，重新檢索/交人工，舊答案不發布。

### RAG-005 · 換Embedding（M2）
**Given：** 不同模型即使同維度。  
**When：** 切換model/profile。  
**Then：** 全量新profile重建；不能混用舊向量。

### RAG-006 · 維度與異常vector（M2）
**Given：** embedding回錯維度/NaN/空向量或3072維。  
**When：** 建立/查詢索引。  
**Then：** 拒絕invalid；不建立不相容的vector HNSW；合法halfvec策略另評測。

### RAG-007 · 中文與精確碼（M2）
**Given：** 題庫含繁簡體/別名/ERR-1048/型號。  
**When：** 比較vector/lexical/hybrid。  
**Then：** 可重現記錄召回與答案，未支持的斷詞不冒稱成熟。

### RAG-008 · 無答案與偽引用（M2）
**Given：** 沒有核准來源或模型輸出S99/任意URL。  
**When：** 結果validation。  
**Then：** 不給假引用/確定答案，轉人工或一次澄清。

### RAG-009 · ANN過濾候選不足（M2）
**Given：** 多workspace資料且某inbox來源稀少。  
**When：** 設定topK檢索。  
**Then：** 不洩漏其他租戶；必要時增加scan/精確fallback並紀錄。


## 串接

### INT-001 · Webhook原始body簽章（M3）
**Given：** 合法HMAC payload。  
**When：** 變空白/改body、錯timestamp/key id/signature。  
**Then：** 修改後拒絕；原始payload才接受。

### INT-002 · Inbound重送/亂序（M3）
**Given：** 相同external event/message與交錯timestamp。  
**When：** 重送10次與亂序發送。  
**Then：** 原始message唯一，保存received/occurred時間，不無故倒退狀態。

### INT-003 · 持久化後才ACK（M3）
**Given：** DB不可用或commit前process crash。  
**When：** sender發webhook。  
**Then：** 非2xx/連線失敗讓上游重送；不回假accepted。

### INT-004 · 外部送出未知（M3）
**Given：** 第三方已收到但HTTP response遺失。  
**When：** client timeout。  
**Then：** delivery_unknown；無外部冪等不得盲重送。

### INT-005 · Outbound重送（M3）
**Given：** receiver第一次500後200。  
**When：** 排程retry/手動retry。  
**Then：** event_id固定，新timestamp/signature，無secret洩漏與audit完整。

### INT-006 · 機器token scope（M3）
**Given：** api client只許某inbox寫入會員訊息。  
**When：** 改brand/inbox或冒充staff。  
**Then：** 拒絕；token只能執行授權動作。


## 部署與恢復

### OPS-001 · Redis失效（M3）
**Given：** 已收到會員訊息但queue enqueue失敗。  
**When：** 關Redis後持續HTTP人工操作，再恢復Redis。  
**Then：** DB保留訊息/任務，dispatcher可恢復，不丟失/重複final。

### OPS-002 · Outbox crash window（M3）
**Given：** message+event commit後尚未發送或發送後尚未ACK。  
**When：** kill dispatcher後重啟。  
**Then：** 至少一次event，consumer去重；不把event永久卡死。

### OPS-003 · 過期lease fencing（M3）
**Given：** worker A lease過期、B重新claim。  
**When：** A晚回並想標完成/發布。  
**Then：** A不能覆蓋B ownership/結果。

### OPS-004 · Queue資源隔離（M3）
**Given：** 大量KB import與AI生成。  
**When：** 同時人工發訊息/claim/watchdog。  
**Then：** core仍可服務；Redis payload只有IDs、不帶完整文件。

### OPS-005 · Timeout關係（M3）
**Given：** 配置core/ai/kb不同工作。  
**When：** 驗證job<supervisor<retry_after且總deadline。  
**Then：** 配置測試拒絕不合理值；無tries=0。

### OPS-006 · 完整還原（M3）
**Given：** 真實備份含DB/附件/secret key metadata及erasure tombstone。  
**When：** 還原至隔離環境。  
**Then：** 可登入/看授權歷史/搜尋/下載；刪除tombstone重播；記錄RPO/RTO。

### OPS-007 · 滾動部署（M3）
**Given：** 有running AI與連線訪客。  
**When：** expand migration/worker重啟/回滾應用。  
**Then：** 舊新版本相容；不執行破壞性fresh migration。

### OPS-008 · 壓測與隔離（M3）
**Given：** SPEC參考資料量/硬體/fake模型。  
**When：** 穩態30分鐘+2倍突發5分鐘。  
**Then：** 附p95/錯誤/資源圖與不變量結果，無實測不能標通過。

### OPS-009 · 匯出與刪除（M3）
**Given：** staff先申請匯出後被撤權；客戶有刪除要求。  
**When：** 下載匯出/執行erasure/還原backup。  
**Then：** 下載重查權限；DB/文件/向量/cache一起清理；不得備份還原復活資料。

### OPS-010 · 授權與供應鏈（M3）
**Given：** 存在Composer/NPM/容器依賴。  
**When：** 執行SBOM/license/audit與lockfile檢查。  
**Then：** 提交實際版本與結果；未掃描者不得標零風險。

## 各階段證據清单

每個milestone須提供實際test report、API contract diff、尚未實測的provider/channel名單與已知限制。一次完整AI答案展示、畫面截圖或AI自行表示「已完成」，均不足以代替上述測試。