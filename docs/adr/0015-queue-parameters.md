# ADR-0015：補齊 events/channels 佇列參數

- 狀態：採納
- 日期：2026-09-26

## 背景

規格 §16.2 要求至少分 core、ai、kb、events/channels supervisor，但 `CONFIG_DEFAULTS.yaml`
只定義前三者的時間參數。

## 決策

| connection | job timeout | supervisor timeout | retry_after | max attempts |
|---|---|---|---|---|
| events | 30s | 40s | 120s | 5 |
| channels | 20s | 30s | 90s | 3 |

- channels 單次外部 HTTP：connect 3s、response 10s（與 webhook 規格一致），job timeout 20s 足以容納。
- Webhook 的 10 次重試由 `webhook_deliveries.next_attempt_at` 排程，不靠 Laravel job 重試。
