<script setup lang="ts">
import { ref, computed, watch } from "vue";
import contract from "../admin-schemas.json";
import { api, errorText, type Row } from "../client";
const props = defineProps<{ prefix: string; permissions: string[] }>(),
    emit = defineEmits<{ error: [message: string] }>();
type Field = {
    type?: string;
    enum?: string[];
    anyOf?: Field[];
    properties?: Record<string, Field>;
    default?: unknown;
    $ref?: string;
};
type Config = {
    path: string;
    label: string;
    permission: string;
    create?: string;
    patch?: string;
};
const sections: Config[] = [
    {
        path: "brands",
        label: "品牌",
        permission: "brand.manage",
        create: "BrandCreate",
        patch: "BrandPatch",
    },
    {
        path: "inboxes",
        label: "收件匣",
        permission: "inbox.manage",
        create: "InboxCreate",
        patch: "InboxPatch",
    },
    {
        path: "members",
        label: "人員",
        permission: "staff.manage",
        create: "MemberCreate",
        patch: "MemberPatch",
    },
    {
        path: "teams",
        label: "團隊",
        permission: "staff.manage",
        create: "TeamCreate",
        patch: "TeamPatch",
    },
    {
        path: "roles",
        label: "角色與權限",
        permission: "roles.assign",
        create: "RoleCreate",
        patch: "RolePatch",
    },
    {
        path: "provider-connections",
        label: "AI 連線",
        permission: "ai.manage",
        create: "ProviderConnectionCreate",
        patch: "ProviderConnectionPatch",
    },
    {
        path: "ai-models",
        label: "模型",
        permission: "ai.manage",
        create: "AiModelCreate",
        patch: "AiModelPatch",
    },
    {
        path: "embedding-profiles",
        label: "Embedding",
        permission: "ai.manage",
        create: "EmbeddingProfileCreate",
        patch: "EmbeddingProfilePatch",
    },
    {
        path: "ai-profiles",
        label: "AI 設定",
        permission: "ai.manage",
        create: "AiProfileCreate",
        patch: "AiProfilePatch",
    },
    {
        path: "api-clients",
        label: "API 存取",
        permission: "integration.manage",
        create: "ApiClientCreate",
    },
    {
        path: "webhook-endpoints",
        label: "Webhook",
        permission: "integration.manage",
        create: "WebhookEndpointCreate",
        patch: "WebhookEndpointPatch",
    },
    {
        path: "webhook-deliveries",
        label: "投遞紀錄",
        permission: "integration.manage",
    },
    {
        path: "identity-issuers",
        label: "會員身分簽發",
        permission: "integration.manage",
        create: "IdentityIssuerCreate",
        patch: "IdentityIssuerPatch",
    },
    {
        path: "channel-connectors",
        label: "外部客服渠道",
        permission: "integration.manage",
        create: "ChannelConnectorCreate",
        patch: "ChannelConnectorPatch",
    },
    {
        path: "business-hours",
        label: "營業時間",
        permission: "inbox.manage",
        create: "BusinessHoursCreate",
        patch: "BusinessHoursPatch",
    },
    {
        path: "macros",
        label: "快捷回覆",
        permission: "automation.manage",
        create: "MacroCreate",
        patch: "MacroPatch",
    },
    {
        path: "tags",
        label: "標籤",
        permission: "automation.manage",
        create: "TagCreate",
        patch: "TagPatch",
    },
    {
        path: "resolution-reasons",
        label: "結案原因",
        permission: "automation.manage",
        create: "ResolutionReasonCreate",
        patch: "ResolutionReasonPatch",
    },
    {
        path: "customer-attributes",
        label: "客戶屬性",
        permission: "automation.manage",
        create: "CustomerAttributeCreate",
        patch: "CustomerAttributePatch",
    },
    { path: "audit-logs", label: "稽核紀錄", permission: "audit.read" },
    { path: "reports/summary", label: "報表", permission: "report.read" },
];
const location = window.location;
const labels: Record<string, string> = {
    name: "名稱",
    slug: "識別名稱",
    brand_id: "品牌",
    inbox_id: "收件匣",
    status: "狀態",
    ai_mode: "AI 模式",
    ai_profile_id: "AI 設定",
    allowed_origins: "允許嵌入的網站來源",
    team_ids: "團隊 ID",
    inbox_ids: "收件匣 ID",
    brand_ids: "品牌 ID",
    display_name: "顯示名稱",
    email: "電子郵件",
    max_active_conversations: "接案上限",
    permissions: "權限",
    connection_id: "供應商連線",
    external_model_id: "模型名稱",
    capabilities: "模型能力",
    context_limit: "上下文上限",
    max_output_tokens: "輸出 token 上限",
    api_key: "API 金鑰",
    base_url: "API 基底網址",
    protocol: "API 協定",
    vendor: "供應商",
    chat_model_id: "文字模型",
    embedding_profile_id: "Embedding 設定",
    prompt_version: "提示詞版本",
    cross_provider_fallback: "允許跨供應商切換",
    allowed_fallback_model_ids: "候選模型 ID",
    dimensions: "向量維度",
    model_id: "模型",
    metric: "距離算法",
    normalization: "正規化",
    task_type: "任務類型",
    scopes: "API 權限",
    allowed_ips: "允許的 IP",
    expires_at: "到期時間",
    url: "投遞網址",
    event_types: "事件類型",
    secret: "簽章密鑰",
    locale: "語系",
    business_hours_id: "營業時間",
    settings: "進階設定",
};
const available = computed(() =>
        sections.filter((s) => props.permissions.includes(s.permission)),
    ),
    section = ref(available.value[0]?.path ?? ""),
    rows = ref<Row[]>([]),
    report = ref<unknown>(null),
    loading = ref(false);
const config = computed(() => sections.find((s) => s.path === section.value)!),
    editor = ref(false),
    editing = ref<Row | null>(null),
    form = ref<Record<string, string>>({}),
    oneTime = ref("");
const schemas = contract as Record<
    string,
    { properties?: Record<string, Field>; required?: string[] }
>;
const schema = computed(
    () =>
        schemas[
            (editing.value ? config.value?.patch : config.value?.create) ?? ""
        ] ?? {},
);
const fields = computed(() =>
    Object.entries(schema.value.properties ?? {}).filter(
        ([key]) => key !== "expected_version" && key !== "egress_policy_id",
    ),
);
function field(f: Field): Field {
    if (f.$ref) return (schemas[f.$ref.split("/").pop()!] as Field) ?? f;
    return f.anyOf?.find((x) => x.type !== "null") ?? f;
}
function choiceOptions(key: string) {
    if (key === "brand_id") return reference.value.brands;
    if (key === "inbox_id") return reference.value.inboxes;
    if (["ai_profile_id"].includes(key)) return reference.value["ai-profiles"];
    if (key === "connection_id") return reference.value["provider-connections"];
    if (["chat_model_id", "model_id"].includes(key))
        return reference.value["ai-models"];
    if (key === "embedding_profile_id")
        return reference.value["embedding-profiles"];
    return undefined;
}
const reference = ref<Record<string, Row[]>>({});
async function load() {
    if (!config.value) return;
    loading.value = true;
    emit("error", "");
    try {
        const result = await api<Row[] | object>(
            `${props.prefix}/${section.value}`,
        );
        if (Array.isArray(result)) {
            rows.value = result;
            report.value = null;
        } else {
            rows.value = [];
            report.value = result;
        }
    } catch (e) {
        emit("error", errorText(e));
    } finally {
        loading.value = false;
    }
}
async function open(row: Row | null = null) {
    editing.value = row;
    oneTime.value = "";
    form.value = {};
    for (const [key, f] of fields.value) {
        const value = row?.[key];
        const type = field(f).type;
        form.value[key] = String(
            value ??
                f.default ??
                (type === "boolean"
                    ? "false"
                    : type === "object"
                      ? "{}"
                      : type === "array"
                        ? "[]"
                        : ""),
        );
        if (value && (type === "array" || type === "object"))
            form.value[key] = JSON.stringify(value, null, 2);
    }
    editor.value = true;
    for (const path of [
        "brands",
        "inboxes",
        "ai-profiles",
        "provider-connections",
        "ai-models",
        "embedding-profiles",
    ]) {
        try {
            reference.value[path] = await api<Row[]>(`${props.prefix}/${path}`);
        } catch {
            /* 沒有權限的參照清單不提供。 */
        }
    }
}
async function save() {
    loading.value = true;
    try {
        const body: Record<string, unknown> = {};
        for (const [key, f] of fields.value) {
            const value = form.value[key];
            if (value === "" || value === undefined) {
                if (schema.value.required?.includes(key)) body[key] = null;
                continue;
            }
            const type = field(f).type;
            body[key] = ["array", "object"].includes(type ?? "")
                ? JSON.parse(String(value))
                : type === "boolean"
                  ? value === "true"
                  : ["integer", "number"].includes(type ?? "")
                    ? Number(value)
                    : value;
        }
        if (editing.value) body.expected_version = editing.value.version;
        const result = await api<Record<string, unknown>>(
            `${props.prefix}/${config.value.path}${editing.value ? "/" + editing.value.id : ""}`,
            editing.value ? "PATCH" : "POST",
            body,
        );
        if (result.token) {
            oneTime.value = String(result.token);
            rows.value = [];
        } else editor.value = false;
        await load();
    } catch (e) {
        emit("error", errorText(e));
    } finally {
        loading.value = false;
    }
}
async function revoke(row: Row) {
    try {
        await api(`${props.prefix}/api-clients/${row.id}/revoke`, "POST", {});
        await load();
    } catch (e) {
        emit("error", errorText(e));
    }
}
async function retry(row: Row) {
    try {
        await api(
            `${props.prefix}/webhook-deliveries/${row.id}/retry`,
            "POST",
            {},
        );
        await load();
    } catch (e) {
        emit("error", errorText(e));
    }
}
async function probe(row: Row) {
    try {
        const models = await api<Row[]>(`${props.prefix}/ai-models`);
        const model = models.find(
            (m) =>
                m.connection_id === row.id &&
                (m.capabilities as Record<string, boolean>)?.text,
        );
        if (!model) throw new Error("請先建立此連線的文字模型。");
        await api(
            `${props.prefix}/provider-connections/${row.id}/probe`,
            "POST",
            { model_id: model.id, capabilities: ["text"] },
        );
        await load();
    } catch (e) {
        emit("error", errorText(e));
    }
}
async function exportReport() {
    try {
        const task = await api<{ id: string }>(
            `${props.prefix}/exports`,
            "POST",
            {
                kind: "report_summary",
                from: new Date(Date.now() - 86400000 * 30).toISOString(),
                to: new Date().toISOString(),
            },
        );
        let count = 0;
        const timer = setInterval(async () => {
            try {
                const result = await api<{
                    state: string;
                    result?: { file_id: string };
                }>(`${props.prefix}/async-tasks/${task.id}`);
                if (result.result?.file_id) {
                    clearInterval(timer);
                    const link = await api<{ url: string }>(
                        `${props.prefix}/files/${result.result.file_id}/download`,
                    );
                    window.open(link.url, "_blank", "noopener");
                } else if (result.state === "failed" || count++ > 30) {
                    clearInterval(timer);
                    emit("error", "匯出尚未完成，可稍後在任務 API 查看。");
                }
            } catch (e) {
                clearInterval(timer);
                emit("error", errorText(e));
            }
        }, 2000);
    } catch (e) {
        emit("error", errorText(e));
    }
}
type Binding = { role_id: string; scope_type: string; scope_id: string | null };
const roleMember = ref<Row | null>(null),
    bindings = ref<Binding[]>([]),
    roleOptions = ref<Row[]>([]),
    scopeOptions = ref<Record<string, Row[]>>({});
async function openRoles(row: Row) {
    try {
        const result = await api<{ role_bindings: Binding[] }>(
            `${props.prefix}/members/${row.id}/role-bindings`,
        );
        bindings.value = result.role_bindings.map(
            ({ role_id, scope_type, scope_id }) => ({
                role_id,
                scope_type,
                scope_id,
            }),
        );
        roleOptions.value = await api<Row[]>(`${props.prefix}/roles`);
        scopeOptions.value = {
            brand: await api<Row[]>(`${props.prefix}/brands`),
            inbox: await api<Row[]>(`${props.prefix}/inboxes`),
            team: await api<Row[]>(`${props.prefix}/teams`),
        };
        roleMember.value = row;
    } catch (e) {
        emit("error", errorText(e));
    }
}
async function saveRoles() {
    if (!roleMember.value) return;
    try {
        await api(
            `${props.prefix}/members/${roleMember.value.id}/role-bindings`,
            "PUT",
            {
                role_bindings: bindings.value.map((b) => ({
                    ...b,
                    scope_id: b.scope_type === "workspace" ? null : b.scope_id,
                })),
            },
        );
        roleMember.value = null;
        await load();
    } catch (e) {
        emit("error", errorText(e));
    }
}
watch(section, load, { immediate: true });
</script>
<template>
    <section class="content admin">
        <div class="section-tabs">
            <button
                v-for="s in available"
                :key="s.path"
                :class="{ active: section === s.path }"
                @click="
                    section = s.path;
                    editor = false;
                "
            >
                {{ s.label }}
            </button>
        </div>
        <div class="section-title">
            <div>
                <h2>{{ config?.label }}</h2>
                <p>依權限管理工作空間。每次更新會檢查資料版本。</p>
            </div>
            <button v-if="config?.create" class="primary" @click="open()">
                ＋ 新增</button
            ><button
                v-if="
                    section === 'reports/summary' &&
                    permissions.includes('export.create')
                "
                @click="exportReport"
            >
                匯出報表
            </button>
        </div>
        <p v-if="loading" role="status">載入中…</p>
        <div v-if="report" class="stat-grid">
            <div
                v-for="(value, key) in report as Record<string, unknown>"
                :key="key"
                class="stat"
            >
                <small>{{ key }}</small
                ><strong>{{ value }}</strong>
            </div>
        </div>
        <div v-if="!rows.length && !report && !loading" class="empty">
            尚無資料。新增第一筆設定開始使用。
        </div>
        <div v-for="row in rows" :key="row.id" class="setting-row">
            <div>
                <h3>
                    {{
                        row.name ??
                        row.display_name ??
                        row.event_type ??
                        row.action ??
                        row.external_model_id ??
                        row.id.slice(-8)
                    }}
                </h3>
                <p>
                    {{ row.status ?? row.state ?? row.health ?? "" }}
                    <code>{{ row.id }}</code>
                </p>
                <div v-if="section === 'inboxes'" class="embed-code">
                    嵌入代碼：<code
                        >&lt;script src="{{
                            location.origin
                        }}/sdk/yacs.js"&gt;&lt;/script&gt;
                        Yacs.init({baseUrl:"{{ location.origin }}",inboxKey:"{{
                            row.public_key
                        }}"});</code
                    >
                </div>
            </div>
            <div class="actions">
                <button
                    v-if="
                        section === 'members' &&
                        permissions.includes('roles.assign')
                    "
                    @click="openRoles(row)"
                >
                    授予角色</button
                ><button v-if="config?.patch" @click="open(row)">編輯</button
                ><button
                    v-if="section === 'api-clients' && row.status === 'active'"
                    @click="revoke(row)"
                >
                    撤銷</button
                ><button
                    v-if="section === 'provider-connections'"
                    @click="probe(row)"
                >
                    測試文字模型</button
                ><button
                    v-if="
                        section === 'webhook-deliveries' &&
                        ['failed', 'paused'].includes(String(row.state))
                    "
                    @click="retry(row)"
                >
                    重試
                </button>
            </div>
        </div>
        <div v-if="roleMember" class="modal-backdrop">
            <section
                class="modal"
                role="dialog"
                aria-modal="true"
                aria-label="授予角色"
            >
                <header>
                    <h2>{{ roleMember.display_name }}的角色</h2>
                    <button @click="roleMember = null">×</button>
                </header>
                <form @submit.prevent="saveRoles">
                    <div v-for="(binding, index) in bindings" :key="index">
                        <label
                            >角色<select v-model="binding.role_id" required>
                                <option value="">選擇角色</option>
                                <option
                                    v-for="role in roleOptions"
                                    :key="role.id"
                                    :value="role.id"
                                >
                                    {{ role.name ?? role.key }}
                                </option>
                            </select></label
                        ><label
                            >範圍<select
                                v-model="binding.scope_type"
                                @change="binding.scope_id = null"
                            >
                                <option value="workspace">整個工作空間</option>
                                <option value="brand">品牌</option>
                                <option value="inbox">收件匣</option>
                                <option value="team">團隊</option>
                            </select></label
                        ><label v-if="binding.scope_type !== 'workspace'"
                            >指定範圍<select
                                v-model="binding.scope_id"
                                required
                            >
                                <option
                                    v-for="scope in scopeOptions[
                                        binding.scope_type
                                    ]"
                                    :key="scope.id"
                                    :value="scope.id"
                                >
                                    {{ scope.name }}
                                </option>
                            </select></label
                        ><button
                            type="button"
                            @click="bindings.splice(index, 1)"
                        >
                            移除此角色
                        </button>
                    </div>
                    <button
                        type="button"
                        @click="
                            bindings.push({
                                role_id: '',
                                scope_type: 'workspace',
                                scope_id: null,
                            })
                        "
                    >
                        ＋ 新增角色</button
                    ><button class="primary">儲存角色</button>
                </form>
            </section>
        </div>
        <div v-if="editor" class="modal-backdrop" @click.self="editor = false">
            <section
                class="modal"
                role="dialog"
                aria-modal="true"
                :aria-label="config.label"
            >
                <header>
                    <h2>{{ editing ? "編輯" : "新增" }}{{ config.label }}</h2>
                    <button
                        aria-label="關閉"
                        @click="
                            editor = false;
                            oneTime = '';
                        "
                    >
                        ×
                    </button>
                </header>
                <div v-if="oneTime">
                    <p>存取 token 僅顯示一次，請立即保存。</p>
                    <textarea
                        :value="oneTime"
                        readonly
                        aria-label="新 API token"
                    ></textarea
                    ><button
                        @click="
                            editor = false;
                            oneTime = '';
                        "
                    >
                        已保存
                    </button>
                </div>
                <form v-else @submit.prevent="save">
                    <label v-for="[key, f] in fields" :key="key"
                        >{{ labels[key] ?? key }}
                        <small v-if="schema.required?.includes(key)">必填</small
                        ><select v-if="choiceOptions(key)" v-model="form[key]">
                            <option value="">請選擇</option>
                            <option
                                v-for="r in choiceOptions(key)"
                                :key="r.id"
                                :value="r.id"
                            >
                                {{
                                    r.name ??
                                    r.external_model_id ??
                                    r.id.slice(-8)
                                }}
                            </option></select
                        ><select v-else-if="field(f).enum" v-model="form[key]">
                            <option value="">請選擇</option>
                            <option
                                v-for="v in field(f).enum"
                                :key="v"
                                :value="v"
                            >
                                {{ v }}
                            </option></select
                        ><input
                            v-else-if="field(f).type === 'boolean'"
                            :checked="form[key] === 'true'"
                            type="checkbox"
                            @change="
                                form[key] = String(
                                    ($event.target as HTMLInputElement).checked,
                                )
                            " /><textarea
                            v-else-if="
                                ['array', 'object'].includes(
                                    field(f).type ?? '',
                                )
                            "
                            v-model="form[key]"
                            rows="3"
                            spellcheck="false"
                        ></textarea
                        ><input
                            v-else
                            v-model="form[key]"
                            :type="
                                key === 'api_key' || key === 'secret'
                                    ? 'password'
                                    : ['integer', 'number'].includes(
                                            field(f).type ?? '',
                                        )
                                      ? 'number'
                                      : 'text'
                            "
                            :required="schema.required?.includes(key)"
                            autocomplete="off" /></label
                    ><button class="primary" :disabled="loading">儲存</button>
                </form>
            </section>
        </div>
    </section>
</template>
