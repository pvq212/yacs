# ADR-0013：對外事件出口首版僅 Webhook

- 狀態：採納
- 日期：2026-09-26

## 決策

- 首版對外事件出口只提供「可重試的 HMAC 簽章 Webhook」（規格 §12.4）。
- outbox → `EventSink` 介面；`WebhookSink` 為唯一內建實作。AMQP/Kafka 等 sink 可日後以擴充註冊。
