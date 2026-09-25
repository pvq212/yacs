"""OpenAPI 編輯輔助函式（供維護者以 Python 片段擴充契約使用）。

用法範例：
    from openapi_lib import Spec
    spec = Spec.load()
    spec.schema('Foo', {...})
    spec.operation('post', '/api/v1/workspaces/{workspace_id}/foos', 'createFoo', tag='Operations',
                   summary='...', security='staff', request='FooCreate', response='Foo', status=201,
                   idempotent=True)
    spec.save()   # 同時重建 openapi.json

約定：
- 成功回應採 `{data, meta}` 封套；list=True 時 data 為陣列並支援 cursor/limit。
- 錯誤回應一律引用 components/responses（與既有契約一致）。
- security：'staff'（session，寫入另需 CSRF）、'visitor'、'integration'、'public'、'webhook'。
"""
from __future__ import annotations

import json
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / "docs/spec/contracts/openapi.yaml"
OUT = ROOT / "docs/spec/contracts/openapi.json"

ERRORS = {
    "401": "Unauthenticated", "403": "Forbidden", "404": "NotFound", "409": "Conflict",
    "410": "CursorExpired", "422": "ValidationFailed", "429": "RateLimited", "503": "TemporarilyUnavailable",
}

SEQ = {"type": "string", "pattern": "^(0|[1-9][0-9]*)$"}
UUID = {"type": "string", "format": "uuid"}
NULLABLE_UUID = {"anyOf": [UUID, {"type": "null"}]}
DATETIME = {"type": "string", "format": "date-time"}


def nullable(schema: dict) -> dict:
    return {"anyOf": [schema, {"type": "null"}]}


def obj(properties: dict, required: list[str] | None = None, additional: bool = False) -> dict:
    out = {"type": "object", "properties": properties, "additionalProperties": additional}
    if required:
        out["required"] = required
    return out


def ref(name: str) -> dict:
    return {"$ref": f"#/components/schemas/{name}"}


class Spec:
    def __init__(self, doc: dict):
        self.doc = doc

    @classmethod
    def load(cls) -> "Spec":
        return cls(yaml.safe_load(SRC.read_text(encoding="utf-8")))

    def save(self) -> None:
        SRC.write_text(yaml.safe_dump(self.doc, allow_unicode=True, sort_keys=False, width=120), encoding="utf-8")
        OUT.write_text(json.dumps(self.doc, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    def schema(self, name: str, schema: dict) -> None:
        self.doc["components"]["schemas"][name] = schema

    def get_schema(self, name: str) -> dict:
        return self.doc["components"]["schemas"][name]

    def operation(
        self,
        method: str,
        path: str,
        operation_id: str,
        *,
        tag: str,
        summary: str,
        security: str = "staff",
        request: str | dict | None = None,
        response: str | dict | None = None,
        status: int = 200,
        list: bool = False,
        idempotent: bool = False,
        params: list[dict] | None = None,
        description: str | None = None,
        errors: list[str] | None = None,
    ) -> None:
        method = method.lower()
        sec = {
            "staff": [{"StaffSession": []}] if method == "get" else [{"StaffSession": [], "CsrfHeader": []}],
            "staff_public": [{"CsrfHeader": []}],
            "visitor": [{"VisitorBearer": []}],
            "integration": [{"IntegrationBearer": []}],
            "public": [],
            "webhook": [{"WebhookHmac": []}],
        }[security]
        parameters = []
        for name in _path_params(path):
            parameters.append({"name": name, "in": "path", "required": True, "schema": UUID if name.endswith("_id") else {"type": "string"}})
        if list:
            parameters += [{"$ref": "#/components/parameters/Cursor"}, {"$ref": "#/components/parameters/Limit"}]
        if idempotent:
            parameters.append({"$ref": "#/components/parameters/IdempotencyKey"})
        parameters += params or []

        responses: dict = {}
        if response is None:
            responses[str(status)] = {"description": "Success"}
        else:
            data = ref(response) if isinstance(response, str) else response
            if list:
                data = {"type": "array", "items": data}
            responses[str(status)] = {
                "description": "Success",
                "content": {"application/json": {"schema": obj({"data": data, "meta": ref("Meta")}, ["data", "meta"])}},
            }
        for code in errors or ERRORS.keys():
            responses[code] = {"$ref": f"#/components/responses/{ERRORS[code]}"}

        op = {"operationId": operation_id, "tags": [tag], "summary": summary, "security": sec, "responses": responses}
        if parameters:
            op["parameters"] = parameters
        if description:
            op["description"] = description
        if request is not None:
            op["requestBody"] = {"required": True, "content": {"application/json": {"schema": ref(request) if isinstance(request, str) else request}}}
        self.doc["paths"].setdefault(path, {})[method] = op

    def remove_operation(self, method: str, path: str) -> None:
        self.doc["paths"].get(path, {}).pop(method.lower(), None)
        if path in self.doc["paths"] and not self.doc["paths"][path]:
            del self.doc["paths"][path]


def _path_params(path: str) -> list[str]:
    out = []
    for part in path.split("/"):
        if part.startswith("{") and part.endswith("}"):
            out.append(part[1:-1])
    return out
