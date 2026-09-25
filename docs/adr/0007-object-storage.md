# ADR-0007：物件儲存：S3 相容（正式 R2），瀏覽器預簽名直傳

- 狀態：採納
- 日期：2026-09-26

## 決策

- 以 Laravel filesystem 抽象存取私有物件；disk 由 `YACS_FILES_DISK` 指定。
  - 正式環境：Cloudflare R2（S3 相容 API，`region=auto`，path-style endpoint）。
  - 開發：SeaweedFS S3 gateway（compose 服務 `s3`）；單元測試：本機 disk。
- 上傳流程：`initiate`（伺服器產生 object key 與短效預簽名 PUT URL）→ 瀏覽器直傳 →
  `complete`（伺服器讀取物件驗證 magic bytes/大小/像素，通過才標 `clean`）。
  本機 disk 不支援預簽名時，改由 API 端點接收上傳內容（同樣經驗證）。
- 下載：每次重查權限後簽發 60 秒短效 URL；R2 的公開開發網域（r2.dev）**不得**用於私有附件。
- R2 對 S3 版本控制支援有限，附件備份以定期複製到第二個 bucket/供應商達成，詳見維運手冊。

## 影響

- 大檔案不佔用 Octane worker；bucket CORS 需允許客服/前台來源的 PUT。
