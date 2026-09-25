# ADR-0002：專案命名 YACS 與授權

- 狀態：採納
- 日期：2026-09-26

## 背景

原始規格包暫名 SupportDesk。專案將以開源方式發佈，需要正式名稱與授權。

## 決策

1. 專案名稱 **YACS（Yet Another Customer Service）**。對外識別字樣集中在 `config/yacs.php` 的 `brand` 區段：
   - HTTP header 前綴 `X-Yacs-*`（例如 webhook 簽章 `X-Yacs-Signature`）。
   - 瀏覽器 SDK 全域物件 `window.Yacs`，SDK 檔名 `yacs.js`。
   - 會員身分 JWT audience `yacs:visitor`；staff session cookie `yacs_session`。
   - npm scope `@yacs/*`；Composer 套件 `yacs/yacs`；PHP namespace 維持 Laravel 慣例 `App\`。
2. 授權：
   - 主程式（後端、客服/運營前端、widget iframe 應用、文件）採 **AGPL-3.0-only**。
   - 會被嵌入第三方網站或複製進宿主程式的部分採 **MIT**：`packages/widget-sdk/`（loader 與 SDK）
     及 `examples/`（宿主後端簽發範例、嵌入範例），避免接入者擔心授權傳染。
3. 外部貢獻採 **DCO**（`Signed-off-by`），不要求 CLA。

## 影響

- AGPL 要求以網路提供服務者對使用者提供修改後原始碼；自架營運者須注意此義務。
- 若未來需要商業雙授權，因未採 CLA，需取得所有貢獻者同意；此為已知取捨。
