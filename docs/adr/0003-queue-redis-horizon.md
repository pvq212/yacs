# ADR-0003：佇列採 Redis + Horizon，PostgreSQL 為任務權威來源

- 狀態：採納
- 日期：2026-09-26

## 背景

規格要求非同步工作（AI 生成、知識索引、事件投遞、渠道送出）與人工客服資源隔離，
且 Redis 故障不得造成已接受訊息或任務遺失。曾評估以 RabbitMQ 取代 Redis 佇列：
RabbitMQ 有 ack/nack、死信佇列、quorum queue 等優點，但 Laravel 無官方 driver、
會失去 Horizon 的監控與 supervisor 管理，且 Redis 仍需保留給快取、限流、presence 與
Reverb scaling，實際上是「多一個元件」而非替換。

## 決策

1. 佇列使用 **Redis（非 Cluster）+ Laravel Horizon**。
2. **任務安全不依賴 Redis**：
   - 所有需要可靠執行的工作先寫入 PostgreSQL `async_tasks`（與業務資料同交易），
     commit 後才把「task id」送進 Redis 佇列。
   - worker 以 CAS 認領（`pending|queued → running`），取得隨機 `lease_token` 與 `lease_expires_at`；
     完成/失敗時必須比對 lease token（fencing），過期 lease 的 worker 無法覆寫結果。
   - `yacs:dispatch-tasks`（scheduler）定期把 `pending`、長時間停在 `queued`、或 lease 過期的
     `running` 任務重新投遞；Redis 清空後可完全由 DB 恢復。
   - 事件採 transactional outbox（`outbox_events`），同樣以 lease 投遞、at-least-once，consumer 以 event_id 去重。
   - 獨立 watchdog 每 10 秒檢查逾期 AI run 並轉人工，不依賴 AI 佇列本身。
3. Redis 分兩個 instance：
   - `redis-queue`：`maxmemory-policy noeviction` + AOF，只放任務訊息（task id 與小型 metadata，目標 < 16 KiB）。
   - `redis-cache`：可淘汰（`allkeys-lru`），放快取、限流、presence 與 Reverb scaling。
4. 佇列 connection 分 `core`、`ai`、`kb`、`events`、`channels`，各自 `retry_after`，
   Horizon 各自 supervisor 與程序數，確保 `job timeout < supervisor timeout < retry_after`（由測試驗證）。
5. Laravel job 本身 `tries` 設為有限值；重試次數以 `async_tasks.max_attempts` 為準，不使用 `tries=0`。

## 影響

- Horizon dashboard 只開放給平台維運者（`is_platform_operator`）。
- 單一 Redis 佇列 instance 故障時，HTTP 訊息保存與人工操作仍可進行（DB 為權威），
  非同步工作延後至 Redis 恢復後由 dispatcher 補投。
- 未來若需 RabbitMQ/Kafka，只需替換「喚醒 worker」與「對外事件 sink」兩個邊界，任務權威模型不變。
