#!/usr/bin/env bash
# 檢查追蹤中（含未追蹤但未忽略）的文字檔是否含 U+FFFD 替換字元，避免中文亂碼進版控。
set -uo pipefail
cd "$(dirname "$0")/.."
bad=$(git grep -lI --untracked $'\xef\xbf\xbd' -- . ':!vendor' ':!node_modules' || true)
if [[ -n "$bad" ]]; then
  echo "$bad"
  echo "發現含 U+FFFD 的檔案（見上方清單），請修正編碼。" >&2
  exit 1
fi
echo "encoding ok"
