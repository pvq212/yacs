# 交付審查（2026-09-30）

已提供可啟動、可操作的客服系統：網站 SDK／聊天元件 → 人工接手與回覆 → 知識庫 → AI 草稿／自動回覆 → 附件 → 結案及滿意度。這是供最終審查的測試版本，尚未部署正式網域，也沒有宣告原規格 85 項生產驗收全部完成。

## 接續來源

已讀取本機 Claude 專案 JSONL 聊天紀錄、記憶與 Git 狀態。原有未提交的 M0 資料表、AI／知識 migration、整合 migration 與安全測試均保留並完成後續功能；沒有將聊天紀錄或其中的秘密提交至公開 repository。Claude 的紀錄位置為 `~/.claude/projects/-home-ubuntu-project-yacs/`。

## 本機實際驗證

| 檢查 | 結果與界線 |
|---|---|
| 後端整合測試 | 51 項通過，使用真實 PostgreSQL 18、PGroonga／pgvector 與受 RLS 約束的 runtime 角色 |
| API 契約 | 每個已文件化 operation 的 route name、HTTP method、path 完整對照；關鍵回應以 OpenAPI 驗證 |
| 靜態檢查 | Pint、PHPStan level 5、Composer validate、Vue／TypeScript 型別檢查通過 |
| 前端單元測試 | 2 項通過 |
| 瀏覽器 | 3 項通過：即時訂閱、訪客／客服往返、R2 附件、備註隔離、CSAT、重載歷史、手機 FAQ、SDK destroy 撤銷 session |
| 真實 AI 閉環 | 額外 1 項通過：實際 Gateway → Horizon → 草稿 → 人工確認 → 訪客收到；一般 CI 明確跳過此付費項目 |
| AI 模型 | `gpt-6-luna`、`claude-sonnet-4-6`、`gemini-3.8-flash` 實際文字呼叫成功，使用各自協定 |
| R2 | `yacs` bucket 全新物件 put/get/delete 通過，瀏覽器新附件完成掃描；新遠端物件以 envelope 加密，應用授權下載才解密 |
| Docker | 開發服務健康，正式映像成功建置；未進行正式 DNS／TLS 上線 |
| CI | workflow 已提供並隨 push 觸發；本機成功不代表遠端 CI 已通過，遠端結果以 GitHub Actions 為準 |

背景任務、AI 與外送的主要回歸包含：過期 lease、晚到 AI、人工先接手、知識 generation 更動、同連線備援、每日生成預算、worker 停止時 watchdog 轉人工、HMAC 去重、503 重試、401 暫停、投遞狀態不降級。安全回歸包含 MFA、CSRF、refresh 重放撤銷、會員 issuer 隔離、停用立刻撤權、跨租戶外鍵及 RLS、附件隔離、短效下載重新授權、匯出到期，以及遠端密文不能移到另一個 object key 解密。

本機 live 檢查 metadata 與隨機測試登入資訊保存在 gitignored `storage/app/private`；AI／R2 連線秘密不在 Git 或正式映像中。AI secret 存於資料庫時使用 envelope 加密。測試用 `.env`／`.env.live` 僅留在本機。

## 外部限制

指定的 `gemini-embedding-2` 未通過 Gateway：Gemini 原生 embedding 端點回 400，OpenAI 相容 embedding 端點回 500／尚未實作。因此沒有將模型冒標為 verified，也沒有啟用依賴它的 embedding profile。已發布知識的中文全文與 FAQ 別名檢索可以使用；向量 adapter／資料表存在，但這個指定模型的真實向量路徑尚未驗證。修復 Gateway 後須重新 probe、建立 profile、重新索引再發布。

本機示範收件匣已設定 `assist_only`、預設 GPT 草稿；三個文字模型均可在管理頁選用。新安裝的系統預設 AI 關閉。

自動審批曾拒絕把所有既有附件批次搬到 R2，理由是缺少對具體舊內容外傳的授權。未執行搬移；每個檔案改為記錄自己的磁碟，舊檔保留原處，新合成測試附件才使用 R2，原有下載仍可用。

## 尚未提供的規格項目

- 原規格的案件 AI 摘要、ToolBroker 業務查詢工具、reranker、跨供應商 fallback、150 題檢索／答案品質評測。
- OCR、受審核 URL 匯入、LINE／WhatsApp 特定平台 adapter；通用 HMAC 渠道僅提供雙向文字，渠道附件明確拒絕。
- 完整自動化規則編輯器、資料刪除／erasure tombstones、自動保留期清理、備份還原／PITR 演練、RPO／RTO 及正式壓測。
- 病毒引擎未配置；內容檢查、圖片重編碼、PDF 檢查及隔離已提供，scanner 可由可信程式擴充。
- 向量檢索的 ANN 切換、索引無中斷遷移與品質門檻未做生產驗收；供應商未提供價格或 usage 時不宣稱精確成本。

若需要原規格 M3 的全部生產驗收，上述項目須另行完成，不能把此測試版本當成已通過 85 項或既定效能目標。

## 開始審查

1. 開啟 `http://localhost:8000/demo`，用另一個瀏覽器在 `/agent` 登入測試帳號。
2. 依 [測試手冊](manual/zh-TW/testing.md) 檢查客服、知識、AI 與管理操作。
3. 查看 [部署手冊](manual/zh-TW/deployment.md) 與 [設定手冊](manual/zh-TW/configuration.md)，正式環境使用獨立秘密、SMTP、私有儲存與網域。
