# ADR-0012：前端技術選型

- 狀態：採納
- 日期：2026-09-26

## 決策

- **客服工作台 / 運營後台**（`resources/staff-app`）：Vue 3 + TypeScript strict + Vite、Vue Router、
  Pinia、vue-i18n、PrimeVue（無樣式 + 自訂主題）、Tailwind CSS。
- **Widget iframe 應用**（`resources/widget-app`）：Vue 3 + TypeScript，不引入 UI 元件庫以控制體積；
  品牌外觀只接受白名單 CSS 變數。
- **Loader / SDK**（`packages/widget-sdk`，MIT）：純 TypeScript、零依賴，輸出 `yacs.js`（目標 gzip < 10 KB），
  以 postMessage（精確 targetOrigin + handshake nonce）與 iframe 溝通。
- 套件管理使用 npm workspaces（Node 24 內建，不額外要求 pnpm）。
- API 型別由 `openapi-typescript` 自 OpenAPI 產生；測試使用 Vitest 與 Playwright。
- Realtime 使用 `laravel-echo` + `pusher-js`（Reverb 相容 Pusher 協定）。
