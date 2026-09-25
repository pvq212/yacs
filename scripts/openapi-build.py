#!/usr/bin/env python3
"""由 docs/spec/contracts/openapi.yaml（唯一可編輯來源）產生 openapi.json，並做基本一致性檢查。

用法：python3 scripts/openapi-build.py [--check]
  --check  只檢查 JSON 是否與 YAML 一致（CI 使用），不寫檔。
"""
import json
import sys
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / "docs/spec/contracts/openapi.yaml"
OUT = ROOT / "docs/spec/contracts/openapi.json"


def main() -> int:
    doc = yaml.safe_load(SRC.read_text(encoding="utf-8"))
    ops = set()
    for path, item in doc["paths"].items():
        for method, op in item.items():
            if method not in ("get", "post", "put", "patch", "delete"):
                continue
            oid = op.get("operationId")
            if not oid or oid in ops:
                print(f"duplicate or missing operationId at {method.upper()} {path}", file=sys.stderr)
                return 1
            ops.add(oid)
    rendered = json.dumps(doc, ensure_ascii=False, indent=2) + "\n"
    if "--check" in sys.argv:
        if not OUT.exists() or OUT.read_text(encoding="utf-8") != rendered:
            print("openapi.json is stale; run scripts/openapi-build.py", file=sys.stderr)
            return 1
        print(f"openapi ok: {len(doc['paths'])} paths, {len(ops)} operations")
        return 0
    OUT.write_text(rendered, encoding="utf-8")
    print(f"wrote {OUT.relative_to(ROOT)}: {len(doc['paths'])} paths, {len(ops)} operations")
    return 0


if __name__ == "__main__":
    sys.exit(main())
