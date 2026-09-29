#!/usr/bin/env bash
# 以測試環境（compose.test.yaml 的資料庫/Redis）執行 artisan 指令，例如：
#   scripts/artisan-test.sh migrate:fresh --database=pgsql_migrator --force
set -euo pipefail
cd "$(dirname "$0")/.."
eval "$(php -r '
$xml = simplexml_load_file("phpunit.xml");
foreach ($xml->php->env as $env) {
    echo "export ".$env["name"]."=".escapeshellarg((string) $env["value"]).";\n";
}')"
exec php artisan "$@"
