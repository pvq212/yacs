# ADR-0011：後端擴充機制（Extension Registry）

- 狀態：採納
- 日期：2026-09-26

## 決策

- 可替換/可擴充的邊界以 interface 定義，並透過 `App\Extensions\ExtensionRegistry` 以「key → 實作」註冊：
  - `channel`：渠道 adapter（`ChannelAdapter`）
  - `ai_protocol`：AI 協定 adapter（Chat/Embedding/Rerank gateway 工廠）
  - `tool`：AI 可用的唯讀業務工具（`AiTool`）
  - `document_parser`：知識文件解析器
  - `file_scanner`：檔案掃描器
  - `lexical_searcher`：lexical 檢索實作
  - `event_sink`：對外事件出口（首版僅 webhook）
- 第三方擴充以 Composer 套件提供 Laravel ServiceProvider，在 `boot()` 呼叫
  `ExtensionRegistry::register(kind, key, factory)`；核心內建實作以同樣方式註冊，不享特權。
- 擴充不得繞過既有授權：工具一律經 `ToolBroker` 取得可信 `ActorContext`；渠道 inbound 一律正規化為
  核心的 Contact/Conversation/Message；AI adapter 不得直接修改對話狀態。
- 前端 UI 擴充插槽列為後續工作。
