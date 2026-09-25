# ADR-0014：首版不內建可觀測性堆疊

- 狀態：採納
- 日期：2026-09-26

## 決策

- 不內建 OpenTelemetry、Prometheus、Grafana。
- 保留：request_id 貫穿 HTTP/log/queue（Laravel Context）、結構化 JSON log、
  `/up` 健康檢查、平台維運者可見的系統健康頁（queue、DB、Reverb、儲存狀態）。
- 規格 §17 的指標以資料庫查詢（報表/健康頁）提供；外部監控整合列為後續工作。
