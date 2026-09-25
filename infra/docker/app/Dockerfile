# YACS 應用映像（Laravel Octane + FrankenPHP）。
#
# 目標（target）：
#   base  ：PHP 擴充與系統工具，所有程序共用（app / reverb / horizon / scheduler）。
#   dev   ：開發用，原始碼以 volume 掛載，附 Composer。
#   prod  ：正式用，內含 vendor 與前端 build 產物，不含開發依賴與原始碼以外檔案。
#
# 基底映像以 digest 鎖定；升級請更新 docs/DEPENDENCIES.md 並執行完整測試。

FROM dunglas/frankenphp:1-php8.4-bookworm AS base
ENV SERVER_NAME=:8000 \
    COMPOSER_ALLOW_SUPERUSER=1
RUN install-php-extensions pdo_pgsql pgsql redis pcntl intl zip bcmath gmp opcache sockets \
 && apt-get update \
 && apt-get install -y --no-install-recommends poppler-utils unzip git \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY infra/docker/app/php.ini /usr/local/etc/php/conf.d/zz-yacs.ini
WORKDIR /app

FROM base AS dev
ENV APP_ENV=local
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000"]

FROM node:24-bookworm-slim AS assets
WORKDIR /build
COPY package.json package-lock.json ./
COPY resources/ resources/
COPY packages/ packages/
RUN npm ci --ignore-scripts
COPY vite.config.ts tsconfig*.json ./
RUN npm run build

FROM base AS prod
ENV APP_ENV=production
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
COPY --from=assets /build/public/build public/build
COPY --from=assets /build/public/sdk public/sdk
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
 && php artisan package:discover --ansi \
 && chown -R www-data:www-data storage bootstrap/cache
USER www-data
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000"]
