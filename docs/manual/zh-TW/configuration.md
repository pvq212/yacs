# 設定

複製 `.env.example` 為 `.env`，使用 `yacs:generate-keys` 產生 APP、加密、lookup、Reverb 金鑰。金鑰更動後重啟 app、Horizon、scheduler、Reverb；不得直接更換加密金鑰後丟棄舊金鑰。資料庫 migration 使用 `yacs_owner`，HTTP 與背景工作使用 `yacs_runtime`。

開發預設 `YACS_FILES_DISK=private`，檔案保存在 `storage/app/private`。不建立公開 storage link。`s3` 與 `r2` 透過 Flysystem 使用 S3 API，瀏覽器透過短效簽名的應用端點上傳／下載，秘密不進 SDK。公開 `r2.dev` 不用於私人客服附件。

R2 設定：

```dotenv
YACS_FILES_DISK=r2
R2_ACCESS_KEY_ID=<測試金鑰識別碼>
R2_SECRET_ACCESS_KEY=<秘密>
R2_ENDPOINT=https://<account>.r2.cloudflarestorage.com
R2_BUCKET=<確切 bucket 名稱>
```

R2 不要求應用列出其他 buckets；應授權指定 bucket 的物件讀寫。先以 `yacs:live-probe --r2 --bucket=<名稱>` 對本機授權的 `.env.live` 進行全新測試物件的 put/get/delete 驗證，通過才切換磁碟。程式不以公開開發 URL 推測 bucket 名稱。[R2 S3 相容性官方說明](https://developers.cloudflare.com/r2/api/s3/api/)。

`YACS_AI_DAILY_CALL_BUDGET` 是每工作空間每日生成呼叫的硬上限（預設 1000），最多兩次實際呼叫／run；輸出上限取 profile、model 與平台的最小值。供應商未回 usage 時維持 unknown，不估成零成本；未設定價格時不提供精確費用。跨供應商 fallback 尚未開放，不能以勾選略過資料政策。

外寄邀請與密碼重設在開發環境進 Mailpit；正式環境請設定自己的 SMTP，不會自動啟用外部郵件服務。自訂 AI／Webhook 目的地每次檢查 DNS 與私有位址並固定連線地址，不跟隨轉址；只有平台環境設定可批准特定內網 host:port。

新建 S3／R2 附件與匯出使用 XChaCha20-Poly1305 envelope 加密，密文與物件 key 綁定；只有通過當前授權的應用下載才解密。`YACS_SECRET_ENCRYPTION_KEY` 必須與資料庫一併妥善備份，遺失後無法恢復檔案。仍建議關閉 bucket 的公開開發入口，客服不依賴公開 URL。

每個檔案記錄自己的 `storage_disk` 與加密格式；切換預設磁碟後不搬移原物件。升級既有環境時先在原磁碟設定下套用 migration，再更改 `YACS_FILES_DISK`。本次已先記錄原本 private 磁碟，既有附件保留在原處；新附件使用 R2 `yacs`。
