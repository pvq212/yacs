#!/usr/bin/env bash
# 建立 YACS 使用的三個資料庫角色與資料庫（僅在資料目錄首次初始化時執行）。
#
#   yacs_owner   ：擁有 schema，負責 migration；一般請求不得使用。
#   yacs_runtime ：應用程式連線角色；非 owner、非 BYPASSRLS，受 Row Level Security 約束。
#   yacs_system  ：NOLOGIN、BYPASSRLS；僅能由 runtime 以 `SET ROLE` 暫時切換，
#                  用於跨 workspace 的系統工作（例如以 public key 找 inbox、outbox 掃描）。
#
# 密碼由環境變數提供；未提供時中止，避免建立無密碼角色。
set -euo pipefail

: "${YACS_DB_OWNER_PASSWORD:?YACS_DB_OWNER_PASSWORD is required}"
: "${YACS_DB_RUNTIME_PASSWORD:?YACS_DB_RUNTIME_PASSWORD is required}"
YACS_DATABASES="${YACS_DATABASES:-yacs}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
  -v owner_pw="$YACS_DB_OWNER_PASSWORD" -v runtime_pw="$YACS_DB_RUNTIME_PASSWORD" <<'SQL'
CREATE ROLE yacs_owner LOGIN PASSWORD :'owner_pw' NOSUPERUSER NOCREATEROLE NOBYPASSRLS;
CREATE ROLE yacs_runtime LOGIN PASSWORD :'runtime_pw' NOSUPERUSER NOCREATEROLE NOBYPASSRLS;
CREATE ROLE yacs_system NOLOGIN NOSUPERUSER NOCREATEROLE BYPASSRLS;
-- runtime 可 SET ROLE yacs_system，但不自動繼承其權限（BYPASSRLS 本就不會繼承）。
GRANT yacs_system TO yacs_runtime WITH INHERIT FALSE, SET TRUE;
SQL

for db in ${YACS_DATABASES//,/ }; do
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres <<SQL
CREATE DATABASE "$db" OWNER yacs_owner;
SQL
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" <<'SQL'
-- 擴充需 superuser 建立；schema 權限交給 owner。
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pgroonga;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
ALTER SCHEMA public OWNER TO yacs_owner;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO yacs_runtime, yacs_system;
-- owner 之後建立的表/序列自動授權給 runtime 與 system（DML only，不含 DDL）。
ALTER DEFAULT PRIVILEGES FOR ROLE yacs_owner IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO yacs_runtime, yacs_system;
ALTER DEFAULT PRIVILEGES FOR ROLE yacs_owner IN SCHEMA public
  GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO yacs_runtime, yacs_system;
ALTER DEFAULT PRIVILEGES FOR ROLE yacs_owner IN SCHEMA public
  GRANT EXECUTE ON FUNCTIONS TO yacs_runtime, yacs_system;
SQL
done
