# 交付依賴（2026-09-30）

PHP 8.4、Node 24、Composer 2。PostgreSQL 18 映像包含 PGroonga／pgvector；測試使用實際 PostgreSQL 與受 RLS 約束的 runtime 角色。

| 套件 | 已鎖定版本 | 來源 |
|---|---|---|
| laravel/framework | v13.33.0 | Composer lock |
| laravel/ai | v1.0.1 | Composer lock |
| laravel/horizon | v5.50.0 | Composer lock |
| laravel/octane | v2.20.0 | Composer lock |
| laravel/reverb | v1.12.0 | Composer lock |
| league/flysystem-aws-s3-v3 | 3.35.3 | Composer lock |
| firebase/php-jwt | v7.2.0 | Composer lock |
| opis/json-schema | 2.6.0 | Composer lock |
| vue | 3.5.43 | npm lock |
| typescript | 5.9.3 | npm lock |
| vite | 8.3.1 | npm lock |
| vitest | 5.0.2 | npm lock |
| @playwright/test | 1.63.0 | npm lock |
| pusher-js | 8.6.0 | npm lock |

FrankenPHP、Node 與 Composer build stage 已鎖 image digest；PostgreSQL 的基底 digest 與 pgvector 套件版本亦在 Dockerfile 固定。Redis 開發環境採 Redis 8，鎖檔不可代替實際升級測試。

Laravel AI SDK API 只在 adapters 內使用；Gemini 明確使用 GenerateContent 原生協定，避免 SDK Interactions 與既有端點不一致。參考 [Laravel AI](https://laravel.com/docs/13.x/ai)、[Gemini](https://ai.google.dev/gemini-api/docs)、[R2](https://developers.cloudflare.com/r2/api/s3/api/)、[PHP CI action](https://github.com/shivammathur/setup-php)、[Node CI action](https://github.com/actions/setup-node)。
