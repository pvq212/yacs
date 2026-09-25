# 文件包檢查報告

日期：2026-09-26；對象是**規格文件與型別/語法示例**，不是Laravel 應用功能。

## 已實際完成的檢查

| 項目 | 結果 |
|---|---|
| OpenAPI YAML可解析，與JSON內容一致 | 通過 |
| OpenAPI內部$ref、path參數、security scheme引用 | 通過 |
| 94條path / 124個操作的operationId唯一性 | 通過 |
| 106個component schemas的JSON Schema meta-validation | 通過 |
| 公開/內部event schemas語法 | 通過 |
| public-message-created.json正向驗證 | 通過 |
| public event拒絕internal visibility欄位 | 通過 |
| event_seq拒絕JSON number，要求十進位字串 | 通過 |
| core/ai/knowledge的job < supervisor < retry_after | 通過 |
| 85個驗收ID唯一，Markdown/YAML案例ID一致 | 通過 |
| 主文[Rxx]均有REFERENCES定義 | 通過 |
| widget-sdk.d.ts TypeScript strict/noEmit | 通過 |
| identity-issuer.php PHP語法lint | 通過；未執行JWT簽發或驗證 |

檢查命令：

```bash
python tools/validate_specs.py
tsc --noEmit --strict --target ES2022 --lib ES2022,DOM contracts/widget-sdk.d.ts
php -l examples/identity-issuer.php
```

## 未完成、不能由上述結果推論的項目

未執行完整OpenAPI專用語意validator套件：本環境無法取得缺少的套件，已用內部引用/路由/JSON Schema檢查替代部分檢查；開發CI仍應加入正式OpenAPI validator與route契約測試。

未建立或執行Laravel應用、真實DB migration、Horizon/Reverb、SDK瀏覽器實作、模型API實機呼叫、第三方渠道端到端、壓測或備份還原。85個Given/When/Then是驗收要求，不是85個已通過測試。

README中的API數量代表本包定義的核心契約；巨集/標籤、邀請/MFA enrollment、報表明細等管理細項仍要在相應階段補齊OpenAPI，不得跳過SPEC已定義的產品需求。
