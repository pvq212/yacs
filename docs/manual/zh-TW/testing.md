# 客服系統測試手冊

## 人工服務

1. 開啟 `/demo`，按「聯絡客服」，傳送問題及文字／圖片附件。
2. 另一個瀏覽器登入 `/agent`，選擇案件並按「接手對話」。將在線狀態切為「可接案」可測試待辦自動分派。
3. 發送一則內部備註，再發公開回覆；訪客只應看到公開回覆與可下載附件。
4. 切換「等待會員」、轉派另一位客服、稍後處理、結案。訪客可送出一至五分 CSAT。
5. 結案後 72 小時內再次傳訊會重新開案；超過期限會建立關聯的新案件。
6. 重新整理訪客頁面確認歷史保留；呼叫 `Yacs.logout()` 後不應看到前一身分的歷史。

工作台更新案件時使用版本檢查；在兩個視窗同時接手／結案，第二個舊版本應收到衝突，草稿保留。

## 知識與 AI

管理員在「知識庫」建立知識庫、選擇所屬品牌及收件匣；新增 FAQ／文字／Markdown／PDF 版本，完成索引後發布。草稿與撤回版本不會成為訪客 FAQ 或 AI 回覆來源。`staff_only` 僅供本機 staff 檢索，不送往外部 chat／embedding，也不會進入 AI 草稿或自動回覆。掃描 PDF 若無可提取文字會回 `OCR_REQUIRED`，本版不假裝完成 OCR。

「管理 → AI 連線／模型」配置協定、端點及秘密，再測試文字模型。建立 AI 設定、選擇已驗證文字模型，編輯收件匣套用 `ai_profile_id`。

- `disabled`：純人工，不呼叫模型。
- `assist_only`：人工接手後按「AI 草稿」，背景生成成功才出現預覽。按「放入草稿」並由客服送出，訪客才看得到。
- `auto_reply`：新訪客問題可由 AI 根據已發布外部知識答覆；無來源、供應商失敗、預算耗盡、超時或明確要求真人時轉人工。正在生成時人工接手，晚到結果不得公開。

本機已授權的 Gateway 設定位於加密資料庫。`.env.live` 僅供 `yacs:configure-live`／`yacs:live-probe`，不進版控或映像。真正連線會產生模型費用；自動測試以 fixtures 驗證，live 檢查記錄另見交付審查。

## 管理與串接

管理頁可建立會員身分簽發者、API client、HMAC 通用渠道、Webhook、營業時間、快捷回覆、標籤、結案原因、客戶屬性。Secret 是只寫欄位；API token 只在建立時顯示。

報表使用實際案件與 CSAT，匯出由背景工作生成 JSON；下載須有目前權限、屬於請求者且未超過 24 小時。

## 背景工作與排錯

```bash
docker compose ps
docker compose logs --tail=50 horizon scheduler reverb
docker compose exec app php artisan horizon:status
docker compose exec app php artisan yacs:watchdog
docker compose exec app php artisan yacs:dispatch
```

附件完成 API 回 202／`quarantined`，掃描工作成功後才 `clean`。前端會等待約 30 秒；持續隔離時檢查 worker、儲存與 scanner。Redis 失效不會把訊息變成成功假象；資料庫任務可由 dispatcher 重新送入佇列。Horizon `/horizon` 僅允許有效 staff session 的平台維運者，普通工作空間 owner 也不能存取。

已配置本機 Gateway 時，明確執行以下 live 瀏覽器測試會產生一次低輸出量模型呼叫，確認 Horizon 生成 → 草稿預覽 → 人工送出；一般 CI 跳過此項。

```bash
YACS_LIVE_BROWSER=1 npx playwright test tests/browser/live-ai.spec.ts
```

此版支援 AI 回覆草稿，`kind=summary` 明確回 `CAPABILITY_UNSUPPORTED`，未提供案件摘要。
