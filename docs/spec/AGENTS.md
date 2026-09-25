# 本地 AI 開發代理工作規則

此資料夾是**規格文件包**，不是既有應用。專案暫名YACS；實作語言PHP/TypeScript，核心技術固定Laravel、PostgreSQL/pgvector、Redis/Horizon、Reverb。

## 必讀與工作順序

1. 讀SPEC、DATA_MODEL、AI_ADAPTERS、API_CONTRACT、OpenAPI、ACCEPTANCE_TESTS與IMPLEMENTATION_PLAN。
2. 若目標目錄已有repo，先檢查現狀與未提交變更，不能覆寫使用者工作。把文件放docs，保留git歷史。
3. 先完成M0，再M1人工閉環，再M2 AI，再M3生產化；不要先做LINE/WhatsApp、漂亮展示或多Agent平台。
4. 小步實作，每個task包含migration/Policy/DTO/測試/文件，不做幾千行巨型Controller。
5. 一個里程碑完成後，輸出已完成ID、實際執行命令與結果、已知限制、下一個可執行task。未執行不寫「測試通過」。

## 不能變更的設計

- workspace/brand/inbox/visitor資料隔離；scope不可由client/model自行擴大。
- staff public reply、internal note、visitor public DTO完全分開。
- DB是訊息/任務/事件權威來源；Reverb/Redis不是唯一聊天紀錄。
- message source/client唯一鍵、outbox、task lease fencing、人機交接atomic final commit必須實作。
- AI off時純人工可工作；assist草稿不自動公開；模型/工具不得直接修改conversation state。
- provider protocol與model capability、Chat/Embedding/Rerank解耦；同維度不同Embedding也不混索引。
- 知識先審核發布；外部AI不能檢索staff_only或其他品牌內容。
- 秘鑰不進前端、URL、log或public API；不自製JWT/HMAC演算法（可使用標準庫計算HMAC與維護中的JWT庫）。

## 套件/程式要求

M0查官方文件、Composer/NPM相容性，提交dependency matrix與lockfile。勿依舊記憶虛構Laravel SDK方法。`laravel/ai`等vendor API只在adapter使用；interface/DTO名稱以本規格為準，不以某個SDK response綁住整個domain。

Backend用Pest或PHPUnit、Laravel Pint、PHPStan/Larastan；Frontend用TypeScript strict、Vitest、Playwright。選定具體版本後將完整命令寫入repo README與CI。不能只寫lint就宣稱功能驗收。

DB必用真實PostgreSQL（含vector extension）的integration test；SQLite只能做非DB純函式測試，不能用於狀態競態/FK/向量驗收。並發test用barrier與多連線，不以sleep亂等。

所有HTTP操作使用FormRequest、Policy與顯式Resource/DTO。避免動態全域config切換workspace/key；Jobs只帶ID及小型metadata。新enum/參數須更新OpenAPI與前端型別。

## 實作期可用命令模板

以下命令依實際選定套件調整並記錄，**不是已執行結果**：

```bash
composer validate --strict
composer audit
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
npm ci
npm run typecheck
npm run test -- --run
npx playwright test
npm run build
```

M0需建立Docker開發環境；測試使用獨立DB與bucket，不碰正式資料。不得把 `migrate:fresh`、清空Redis、destroy volume、廣泛刪檔放進正式部署腳本。測試環境cleanup必須驗證環境名稱與專用資源。

## 完成與誠實報告

沒有API key、SMTP或LINE帳號時，用fixtures/mock測核心；把live驗證標為blocked/未測，不自動創建付費資源或發送外部訊息。不存在的第三方API不能以TODO/空成功回應冒充完成。

衝突/缺漏先建立ADR與contract補充，再實作；安全與資料完整性需求不可為了讓demo跑起來而移除。尚未收錄OpenAPI的運營細項CRUD是已知待展開範圍，須在該task實作前加入具體schema。

需要產品負責人配置的值（實際provider/model/key/來源文件/網域/保存政策）不得自行捏造；開發預設AI disabled，使用示範測試資料。文件包中的example.com/.example域名與UUID均為佔位示例。
