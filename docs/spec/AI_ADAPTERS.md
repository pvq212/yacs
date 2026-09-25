# AI供應商與檢索模組規格

## 1. 設計邊界

業務層只認 `ChatGateway`、`EmbeddingGateway`、`RerankGateway`、`KnowledgeRetriever`、`ToolBroker`，不依賴任何vendor response class。第一個具體實作為 `LaravelAiAdapter`；缺少功能時，以其他維護中的library/原生HTTP transport實作同一契約，不讓Controller出現一排vendor if/else。

不建立另一套Dify/Agent SaaS作必須依賴；未來可把外部工作流接成新的Gateway，但仍受同樣授權、知識scope、deadline與交接控制。

## 2. SDK選用查核（2026-09-26）

| 選項 | 本次官方文件狀態 | 本案決策 |
|---|---|---|
| Laravel `laravel/ai` | Laravel第一方統一SDK，有OpenAI/Anthropic/Gemini及自訂endpoint；能力依provider而異 [R02] | 優先；M0鎖版本、驗證實際協定，不假設涵蓋vendor每個新功能 |
| OpenAI `openai-php/client` | OpenAI官方library頁列為community library，不是OpenAI官方PHP SDK [R09] | Laravel adapter不足時的候選，先核對release/維護/安全公告 |
| Anthropic `anthropic-ai/sdk` | 官方PHP SDK，文件仍標示beta [R10] | 可選，但不因「官方」忽略beta風險；封裝、鎖版、測試後再用 |
| Gemini | Google官方GenAI清單本次未列PHP [R11] | 優先Laravel adapter；必要時用有維護證據的PHP library或受測HTTP adapter，不虛構官方PHP SDK |

本規格未對任何特定Composer修補版本做實機測試。M0須交付dependency matrix，註明package、version、license、最低PHP、維護來源、protocol支持、fixture與live test狀態。不要一次引入三套SDK只為「看起來完整」。

## 3. Protocol與model能力

| protocol | 主要格式 | 相容性最低要求 |
|---|---|---|
| openai_responses | `/v1/responses`的input/output items | 文字生成、error mapping、usage；streaming/tools/structured output另驗證 [R18] |
| openai_chat_completions | `/v1/chat/completions`的messages/choices | 文字生成；相容服務的tools/JSON/usage/模型參數可能不同，逐一宣告 |
| anthropic_messages | `/v1/messages`的content blocks、獨立system | 正確content block、stop reason、usage；不能把thinking block當回答 |
| gemini_generate_content | models generateContent/streamGenerateContent、contents/parts | 正確role/parts、finish reason、safety/empty response；Gemini原生格式非OpenAI格式 [R19] |

Provider connection記錄vendor與protocol兩個欄位。例如vendor="private_gateway"、protocol="openai_chat_completions"。Base URL是否含/v1由adapter規約固定；禁止曖昧拼接成/v1/v1。URL驗證後才可測試連線；自訂headers走allowlist，secret在服務端注入。

每個model有capabilities：text、stream、json_schema、tools、vision、embeddings、rerank、cancellation、usage、context_limit、max_output。設定頁以capability決定可設定欄位，不支援時回`CAPABILITY_UNSUPPORTED`，不得偷偷忽略。

Anthropic聊天不代表它提供embedding。Embedding/rerank可選不同供應商，並各自接受資料處理政策檢查；不能因Chat選Claude就自動填入不存在的Claude embedding endpoint。

## 4. 內部DTO契約

以下為應實作的領域契約，名稱是本案自訂，不是假裝SDK已有相同class。

### ChatRequest

- request_id、workspace_id、actor_context_id、run_id、deadline_at。
- connection/model/profile的immutable snapshot，非model名稱一個字串而已。
- system_policy（本地版控）、conversation messages（role/content segments）、retrieved_sources。
- output_schema（可選）、tool_definitions（白名單）、max_output_tokens與經capability允許的options。
- data_policy：allowed_destinations、sensitive_fields_redacted、fallback_allowed。
- cancellation callback/token；不把secret放到可log/序列化DTO。

### ChatResult

- text、finish_reason(normalized)、source_refs、tool_calls、provider_request_id_safe。
- usage(input/output/cached token + `known | estimated | unavailable`)；禁止把未知當0。
- model_id、connection_id、latency、warnings。
- 不返回可公開的raw response；raw provider payload只在獨立短期加密debug選項存在。

### StreamEvent

`started | text_delta | tool_call_delta | usage | completed | refused | failed`。

外部delta只在adapter內正規化，UTF-8、SSE chunk邊界與JSON fragments需測試。供應商串流支援不等於一定要對訪客逐token公開。首版autopilot全量buffer + validation + atomic publish；客服草稿可串流。若未來對外開啟provisional stream，需明確承認已送達token不能回收，並另設UI協定/安全驗收。

### EmbeddingRequest / Result

input_texts、profile_id、model_id、requested_dimensions（僅模型支援時）、task_type、deadline。

Result為對應相同順序的finite float vectors、實際dimensions、usage。回傳長度、維度、NaN/Inf、空字串策略不符即失敗；不以全零向量補救。query embedding與document embedding必須用相容的同profile/task規則。

### RerankRequest / Result

query + 已授權candidate IDs/texts + top_n → candidate IDs/score/order。不得返回原candidate之外的id；錯誤可按profile配置退回原始排序，但必須記錄 degraded。

## 5. ToolBroker與結構化結果

Autopilot內部建議結果schema：

```json
{
  "action": "answer",
  "answer": "依目前規則，您可以從會員中心查看案件進度。",
  "source_refs": ["S1"],
  "handoff_reason": null
}
```

action只允許 `answer | clarify | handoff`。由程式驗證schema、引用集合、字數/連結、政策與對話epoch；模型沒有直接修改conversation/assignee/status的權限。原生structured output不支援時可用明確文字格式+parser，但invalid輸出不得直接給訪客；此降級能力須有測試並可停用。

ToolBroker每次執行都重查ActorContext：會員是否已驗證、resource是否本人、workspace/inbox是否匹配。readonly工具最多2次/AI run，總tool時間預算10秒作起點；工具回應有schema/最大bytes，不把完整會員資料或歷史訂單全部塞給LLM。

Prompt injection來源可來自會員、網站文件、PDF、工具結果或社群訊息。把它們標記為data、限制工具與輸出、最小權限；不能只靠一句「忽略惡意指令」宣稱解決。

## 6. Chat生成與handoff算法

1. Receive task，CAS claim lease；確認deadline尚未到。
2. Load當前conversation + latest_customer_message + inbox/AI設定 + authorized KB snapshot。
3. 若human/closed/kill-switch/明確requestHuman：取消AI或轉人工，不呼叫provider。
4. 檢查workspace+brand+inbox budget並預留保守成本；不同provider不得跨越data_policy。
5. 正規化query、retrieval、選取source；無足夠來源則fallback核准澄清/人工，不硬生答案。
6. 建立受限制ChatRequest，總deadline控制provider重試/工具時間。
7. 收到結果後schema/引用/finish reason檢查；stream被截斷、refusal、content_filter或max_tokens截斷都不得當成完整成功。
8. 短transaction重新檢查run/lease/epoch/latest input/generation/profile version，原子建立唯一final message與outbox。
9. 失效結果標記stale/cancelled，不發給訪客；總deadline到或不可恢復錯誤進human_queue。
10. 結算usage與budget。上游可能已計費但取消不代表退款；unknown成本需保守保留估計。

新會員輸入把舊run變stale後，可以為新epoch建立run；不能在同一未取消run中追加不同問題造成語境錯亂。每個conversation一次最多一個有效autopilot run。

## 7. 逾時、限流與fallback

Connection timeout5秒、單次generate45秒、run總deadline90秒；至多2次provider嘗試，只有尚未對外發布且剩餘budget足夠時才重試。Retryable為網路暫時故障、429、部分5xx；400/401/403/unsupported parameter應顯示配置錯誤/告警，不以反覆重試解決。

SDK內建retry可關閉或縮到明確次數，不能SDK重試3次乘上queue3次再乘fallback3家。共享併發控制按workspace+connection+model，超出時等候仍計入run deadline；轉人工永遠優先於盲等。

Fallback分兩種：同provider允許的model fallback；跨provider fallback需data_policy明確同意，且目的地在allowlist。fallback仍只使用相同已授權資料，不放大歷史對話範圍；記錄實際provider/model與原因。

預設聊天連續5次基礎設施失敗開circuit60秒，半開1次測試；參數可調。budget超限立即停止新auto generation，但人工客服可用；顯示真實人工服務，不把超額錯誤原文丟給會員。

## 8. Connection管理與版本安全

配置來源可選環境secret或DB中的secret_ref；後台只回masked/present，不回key原文。Workspace admin可選平台已批准的egress target；需新增內網host/port由platform operator批准。

每次變更產生新configuration_version與audit；active run使用immutable snapshot，但發布前若critical設定改動/停用，必須廢止。長駐PHP環境不得以 `config(['ai.providers.current.key'=>...])` 更動共用全域設定而不保證隔離；以每請求/每job instance注入相應client，並用交錯workspace A/B測試驗證。

模型列表可人工填寫，因為不是每個endpoint有可靠list models API；人工填寫模型必須通過probe，UI記錄「text已驗證/embedding未驗證」，不能測一個health endpoint就把所有能力亮綠燈。

## 9. 最低測試矩陣

每個protocol至少測normal text、multi content blocks、usage缺失、invalid UTF-8邊界/stream fragments、400/401/429/500、timeout、refusal、max_tokens截斷、cancellation、unknown fields。工具/JSON/vision等只有宣稱支援時才必測；不支援需穩定回明確錯誤。

至少一個真實Chat provider與一個真實Embedding profile做live smoke；沒有實際API key的供應商只標示fixture-tested。產出adapter capability report，附確切套件/模型/協定版本與測試日期。
