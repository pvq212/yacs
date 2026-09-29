# YACS 應用映像（Laravel Octane + FrankenPHP）。
#
# 目標（target）：
#   base  ：PHP 擴充與系統工具，所有程序共用（app / reverb / horizon / scheduler）。
#   dev   ：開發用，原始碼以 volume 掛載，附 Composer。
#   prod  ：正式用，內含 vendor 與前端 build 產物，不含開發依賴與原始碼以外檔案。
#
# 基底映像以 digest 鎖定；升級請更新 docs/DEPENDENCIES.md 並執行完整測試。

FROM dunglas/frankenphp:1-php8.4-bookworm@sha256:3672a0d093efc94375f3457467fa11d25a39f51c7969c4a827967ab29eb00eda AS base
ENV SERVER_NAME=:8000 \
    COMPOSER_ALLOW_SUPERUSER=1
RUN install-php-extensions pdo_pgsql pgsql redis pcntl intl zip bcmath gmp opcache sockets gd \
 && apt-get update \
 && apt-get install -y --no-install-recommends poppler-utils unzip git \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2@sha256:9715c7f69044da2a212a5fbde29ee7da24e364d426560ae6367b060236f847d7 /usr/bin/composer /usr/bin/composer
COPY infra/docker/app/php.ini /usr/local/etc/php/conf.d/zz-yacs.ini
WORKDIR /app

FROM base AS dev
ENV APP_ENV=local
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000"]

FROM node:24-bookworm-slim@sha256:0e0ff40c39bc087845bfb27465a0df4ea419520094bc35842ff83dd8cbe6f9b6 AS assets
WORKDIR /build
COPY package.json package-lock.json ./
COPY resources/ resources/
COPY packages/ packages/
RUN npm ci --ignore-scripts
COPY vite*.ts tsconfig*.json ./
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
 && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache
USER www-data
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000"]
