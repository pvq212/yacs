# 正式部署

`compose.prod.yaml` 提供 app、Horizon、獨立 scheduler、Reverb、PostgreSQL、兩個 Redis 與 Caddy HTTPS 入口。服務需要自己的網域／SMTP／儲存；此交付尚未替任何正式網域上線。

1. 複製 `.env.example` 為 `.env`，產生密碼及金鑰。設定 `YACS_DOMAIN`、`APP_URL=https://<網域>`、三個資料庫密碼、SMTP、R2 或私有持久磁碟。資料庫密碼不是開發範例值。
2. `docker compose -f compose.prod.yaml build` 建置映像；`docker compose -f compose.prod.yaml up -d --wait postgres redis-cache redis-queue` 啟動內部服務。
3. `docker compose -f compose.prod.yaml run --rm --no-deps app php artisan migrate --database=pgsql_migrator --force` 套用 schema。migration owner 與 runtime 帳號分開，不使用 superuser 執行應用。
4. `docker compose -f compose.prod.yaml run --rm --no-deps app php artisan yacs:install` 互動建立第一位管理員與工作空間。需要維運頁時加 `--platform-operator`；普通 owner 無法查看 Horizon。密碼不寫在命令參數。
5. `docker compose -f compose.prod.yaml up -d --wait` 啟動全部服務，DNS 指向主機並開放 80／443。首次登入要求 MFA；在管理後台建立品牌、收件匣、允許來源、角色範圍與知識。

在主機使用已安裝 PHP／Composer 時，可先執行 `php artisan yacs:generate-keys` 寫入 `.env`。在無主機 PHP 的部署環境，以 dev image 暫時掛載本機 `.env` 與專案執行產生金鑰，再啟動新的 production 程序。正式映像不包含 `.env`，每次以環境／secret injection 提供相同穩定金鑰。

正式設定強制 `APP_ENV=production`、`APP_DEBUG=false`、安全 cookie 與 MFA；僅 Caddy 對外，資料庫、Redis 與 app 不映射主機 port。此 Compose 只有 Caddy 對外，應用預設信任內部私有網段的代理 header，以正確產生 HTTPS 簽名下載網址。若使用其他代理或不同 Docker 網段，將 `YACS_TRUSTED_PROXIES` 限定為實際代理位址。Caddy 將 `/app/*` WebSocket 流量送入 Reverb，其餘交由 app。

變更版本後先備份資料庫、物件與加密金鑰，再建置、套用 migration、重啟四種程序並驗證健康與登入。部署流程沒有 `migrate:fresh`、清 Redis 或移除 volume。備份保存與還原策略需由維運另外制定、在隔離環境演練；此版未提供自動還原／刪除治理工具。
