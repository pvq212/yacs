# YACS PostgreSQL 映像：PostgreSQL 18 + PGroonga（中文全文檢索）+ pgvector（向量檢索）。
# 版本以 digest 鎖定；升級時請同步更新 docs/DEPENDENCIES.md 並跑完整整合測試。
FROM groonga/pgroonga:latest-debian-18@sha256:271cde790dea6bed2ac21544371fb1862f2d1ae378b009435c5fc80d971d380f

ARG PGVECTOR_VERSION=0.8.6-1.pgdg13+2
RUN apt-get update \
 && apt-get install -y --no-install-recommends "postgresql-18-pgvector=${PGVECTOR_VERSION}" \
 && rm -rf /var/lib/apt/lists/*

# 首次初始化時建立角色與擴充；既有資料目錄不會重跑。
COPY initdb/ /docker-entrypoint-initdb.d/
