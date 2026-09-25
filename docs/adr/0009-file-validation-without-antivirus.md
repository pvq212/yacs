# ADR-0009：檔案檢查：預設不掃毒，保留 Scanner 介面

- 狀態：採納
- 日期：2026-09-26

## 背景

產品負責人判斷首版不需要病毒掃描（ClamAV 需額外 1–2 GB 記憶體）。

## 決策

- `FileScanner` 介面保留；預設實作 `ContentValidationScanner` 執行與掃毒無關的基本防護：
  magic bytes 偵測（不信任宣告的 Content-Type）、大小上限、圖片像素上限（防解壓縮炸彈）、
  PDF 頁數上限、禁止類型（SVG/HTML/執行檔/壓縮檔）。
- 狀態仍為 `pending_upload → quarantined → clean | rejected`；未 `clean` 不可附加、下載或給 AI。
- 掃描器例外時 fail closed（維持 quarantined），文字訊息不受影響。
- 需要掃毒時，以擴充套件註冊 ClamAV 等 scanner（見 ADR-0011）。
- 圖片移除 EXIF 以重新編碼達成（GD），失敗則拒絕。
