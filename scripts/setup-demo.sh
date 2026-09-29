#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ ! -f .env ]]; then cp .env.example .env; chmod 600 .env; fi
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
docker compose build app
docker compose up -d --wait postgres redis-cache redis-queue mailpit
docker compose run --rm --no-deps app composer install --prefer-dist --no-interaction
docker compose run --rm --no-deps app php artisan yacs:generate-keys
npm ci
npm run build
docker compose run --rm --no-deps app php artisan migrate --database=pgsql_migrator --force
docker compose run --rm --no-deps app php artisan yacs:demo
# Docker 建立的 0600 示範帳號仍保持私有，由目前主機使用者持有。
docker compose run --rm --no-deps app chown -R "$(id -u):$(id -g)" /app/storage /app/bootstrap/cache
docker compose up -d --wait app reverb horizon scheduler
