<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from "vue";
import {
    api,
    ApiError,
    errorText,
    statusLabel,
    sendOnEnter,
    upload,
    type Conversation,
    type Message,
    type Snapshot,
    type Row,
} from "../client";
import Thread from "./Thread.vue";
import { subscribe } from "../realtime";
import Admin from "./Admin.vue";
import Knowledge from "./Knowledge.vue";
type Me = {
    name: string;
    email: string;
    mfa_enabled: boolean;
    mfa_enrollment_required: boolean;
};
const user = ref<Me | null>(null),
    email = ref(""),
    password = ref(""),
    challenge = ref(""),
    code = ref("");
const error = ref(""),
    busy = ref(false),
    workspaces = ref<Row[]>([]),
    workspace = ref(""),
    membership = ref(""),
    permissions = ref<string[]>([]);
const sessionId = ref("");
let disconnect = () => {};
const tab = ref("conversations"),
    filter = ref(""),
    query = ref(""),
    presence = ref("offline");
const conversations = ref<Conversation[]>([]),
    selected = ref<Conversation | null>(null),
    messages = ref<Message[]>([]),
    members = ref<Row[]>([]),
    target = ref("");
const contact = ref<Row | null>(null),
    macros = ref<Row[]>([]),
    macro = ref(""),
    tags = ref<Row[]>([]),
    selectedTags = ref<string[]>([]),
    reasons = ref<Row[]>([]);
const draft = ref(""),
    note = ref(false),
    waiting = ref(false),
    attachments = ref<{ id: string; name: string }[]>([]),
    resolution = ref("answered"),
    aiDraft = ref(""),
    aiRun = ref("");
const prefix = computed(() => `workspaces/${workspace.value}`),
    can = (p: string) => permissions.value.includes(p);
let timer: ReturnType<typeof setInterval> | undefined,
    pendingId = "",
    pendingBody = "",
    polling = false;
async function run(action: () => Promise<void>) {
    error.value = "";
    busy.value = true;
    try {
        await action();
    } catch (e) {
        error.value = errorText(e);
        if (e instanceof ApiError && e.status === 409)
            await snapshot().catch(() => {});
    } finally {
        busy.value = false;
    }
}
async function login() {
    await run(async () => {
        const result = await api<{
            mfa_required: boolean;
            challenge_id: string;
        }>("auth/login", "POST", {
            email: email.value,
            password: password.value,
        });
        password.value = "";
        if (result.mfa_required) challenge.value = result.challenge_id;
        else await loadUser();
    });
}
async function verify() {
    await run(async () => {
        await api("auth/mfa/verify", "POST", {
            challenge_id: challenge.value,
            code: code.value,
        });
        challenge.value = "";
        await loadUser();
    });
}
async function loadUser() {
    user.value = await api<Me>("me");
    if (user.value.mfa_enrollment_required) {
        tab.value = "account";
        return;
    }
    workspaces.value = await api<Row[]>("workspaces");
    if (!workspace.value) workspace.value = workspaces.value[0]?.id ?? "";
    if (workspace.value) await switchWorkspace();
}
async function switchWorkspace() {
    disconnect();
    selected.value = null;
    messages.value = [];
    const me = await api<{
        membership_id: string;
        permission_codes: string[];
        staff_session_id: string;
    }>(`${prefix.value}/me`);
    membership.value = me.membership_id;
    sessionId.value = me.staff_session_id;
    permissions.value = me.permission_codes;
    if (can("conversation.read")) {
        await refreshList();
        macros.value = await api<Row[]>(`${prefix.value}/macros`);
        tags.value = await api<Row[]>(`${prefix.value}/tags`);
        reasons.value = await api<Row[]>(`${prefix.value}/resolution-reasons`);
    }
    if (can("staff.manage"))
        members.value = await api<Row[]>(`${prefix.value}/members`);
}
async function refreshList() {
    const params = new URLSearchParams();
    if (filter.value) params.set("status", filter.value);
    if (query.value) params.set("q", query.value);
    conversations.value = await api<Conversation[]>(
        `${prefix.value}/conversations?${params}`,
    );
}
async function select(c: Conversation) {
    disconnect();
    disconnect = subscribe(sessionId.value, c.id, true, prefix.value, () => {
        snapshot().catch(() => {});
        refreshList().catch(() => {});
    });
    if (draft.value || attachments.value.length) {
        localStorage.setItem(
            `yacs-draft-${workspace.value}-${selected.value?.id}`,
            draft.value,
        );
    }
    selected.value = c;
    draft.value =
        localStorage.getItem(`yacs-draft-${workspace.value}-${c.id}`) ?? "";
    attachments.value = [];
    aiDraft.value = "";
    aiRun.value = "";
    await snapshot();
    if (c.contact_id)
        contact.value = await api<Row>(
            `${prefix.value}/contacts/${c.contact_id}`,
        );
    selectedTags.value = (
        await api<Row[]>(`${prefix.value}/conversations/${c.id}/tags`)
    ).map((t) => t.id);
}
async function snapshot() {
    const id = selected.value?.id;
    if (!id) return;
    const data = await api<Snapshot>(
        `${prefix.value}/conversations/${id}/snapshot`,
    );
    if (selected.value?.id !== id) return;
    selected.value = data.conversation;
    messages.value = data.messages;
}
async function action(name: string, extras: Record<string, unknown> = {}) {
    const c = selected.value;
    if (!c) return;
    await run(async () => {
        selected.value = await api<Conversation>(
            `${prefix.value}/conversations/${c.id}/${name}`,
            "POST",
            { expected_version: c.version, ...extras },
        );
        await snapshot();
        await refreshList();
    });
}
async function send() {
    const c = selected.value;
    if (!c || (!draft.value.trim() && !attachments.value.length) || busy.value)
        return;
    await run(async () => {
        const content = JSON.stringify([
            draft.value,
            note.value,
            attachments.value,
        ]);
        if (content !== pendingBody) {
            pendingId = crypto.randomUUID();
            pendingBody = content;
        }
        await api(`${prefix.value}/conversations/${c.id}/messages`, "POST", {
            client_message_id: pendingId,
            body_text: draft.value,
            attachment_ids: attachments.value.map((a) => a.id),
            visibility: note.value ? "internal" : "public",
            expected_version: c.version,
            after_send: waiting.value ? "waiting_customer" : "keep_open",
        });
        draft.value = "";
        attachments.value = [];
        pendingBody = "";
        localStorage.removeItem(`yacs-draft-${workspace.value}-${c.id}`);
        await snapshot();
        await refreshList();
    });
}
async function addFile(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (file)
        await run(async () => {
            attachments.value.push({
                id: await upload(file, prefix.value),
                name: file.name,
            });
        });
    (e.target as HTMLInputElement).value = "";
}
async function download(id: string) {
    await run(async () => {
        const link = await api<{ url: string }>(
            `${prefix.value}/files/${id}/download`,
        );
        window.open(link.url, "_blank", "noopener");
    });
}
function applyMacro() {
    const row = macros.value.find((m) => m.id === macro.value);
    if (row)
        draft.value = String(row.body_template)
            .replace(
                /\{\{\s*contact.name\s*\}\}/g,
                String(contact.value?.name ?? "會員"),
            )
            .replace(/\{\{\s*brand.name\s*\}\}/g, "我們");
    macro.value = "";
}
async function saveTags() {
    if (!selected.value) return;
    await run(async () => {
        selected.value = await api<Conversation>(
            `${prefix.value}/conversations/${selected.value!.id}/tags`,
            "PUT",
            {
                expected_version: selected.value!.version,
                tag_ids: selectedTags.value,
            },
        );
        await snapshot();
    });
}
async function draftAi() {
    if (!selected.value) return;
    await run(async () => {
        const response = await api<{ id: string }>(
            `${prefix.value}/conversations/${selected.value!.id}/ai-drafts`,
            "POST",
            {},
        );
        aiRun.value = response.id;
    });
}
async function tick() {
    if (polling || busy.value || !user.value || !workspace.value) return;
    polling = true;
    try {
        if (tab.value === "conversations") {
            await refreshList();
            await snapshot();
            if (aiRun.value) {
                const result = await api<{
                    state: string;
                    draft_text: string | null;
                    failure_code: string | null;
                }>(`${prefix.value}/ai-runs/${aiRun.value}`);
                if (result.draft_text) {
                    aiDraft.value = result.draft_text;
                    aiRun.value = "";
                } else if (
                    ["failed", "cancelled", "stale"].includes(result.state)
                ) {
                    error.value = `AI 草稿未完成：${result.failure_code ?? result.state}`;
                    aiRun.value = "";
                }
            }
        }
        if (presence.value !== "offline")
            await api(`${prefix.value}/presence`, "PUT", {
                state: presence.value,
            });
    } catch (e) {
        if (e instanceof ApiError && e.status === 401) {
            user.value = null;
            error.value = "登入已失效，請重新登入；未送出草稿已保留。";
        }
    } finally {
        polling = false;
    }
}
async function setPresence() {
    await run(async () => {
        await api(`${prefix.value}/presence`, "PUT", { state: presence.value });
    });
}
async function logout() {
    await run(async () => {
        await api("auth/logout", "POST", {});
        user.value = null;
        workspace.value = "";
        presence.value = "offline";
    });
}
const setup = ref<{ secret: string; otpauth_uri: string } | null>(null),
    recovery = ref<string[]>([]);
async function setupMfa() {
    await run(async () => {
        setup.value = await api("me/mfa/setup", "POST", {
            password: password.value,
        });
        password.value = "";
    });
}
async function confirmMfa() {
    await run(async () => {
        const result = await api<{ recovery_codes: string[] }>(
            "me/mfa/confirm",
            "POST",
            { code: code.value },
        );
        recovery.value = result.recovery_codes;
        setup.value = null;
        await loadUser();
    });
}
onMounted(async () => {
    try {
        await loadUser();
    } catch (e) {
        if (!(e instanceof ApiError && e.status === 401))
            error.value = errorText(e);
    }
    timer = setInterval(tick, 4000);
});
onUnmounted(() => {
    clearInterval(timer);
    disconnect();
    if (selected.value && draft.value)
        localStorage.setItem(
            `yacs-draft-${workspace.value}-${selected.value.id}`,
            draft.value,
        );
});
</script>
<template>
    <div v-if="!user" class="login-page">
        <div class="login-brand">
            <span class="mark">y</span>
            <p>YACS 客服中心</p>
            <h1>讓每一段對話，<br />都有好的下一步。</h1>
            <p>串起團隊、知識與 AI 的客服工作空間。</p>
        </div>
        <form
            class="login-card"
            @submit.prevent="challenge ? verify() : login()"
        >
            <small>歡迎回來</small>
            <h2>{{ challenge ? "雙重驗證" : "登入工作空間" }}</h2>
            <template v-if="!challenge"
                ><label
                    >電子郵件<input
                        v-model="email"
                        type="email"
                        autocomplete="username"
                        required /></label
                ><label
                    >密碼<input
                        v-model="password"
                        type="password"
                        autocomplete="current-password"
                        required /></label></template
            ><label v-else
                >驗證碼或復原碼<input
                    v-model="code"
                    autocomplete="one-time-code"
                    required
            /></label>
            <p v-if="error" class="error" role="alert">{{ error }}</p>
            <button class="primary" :disabled="busy">
                {{ busy ? "請稍候…" : "登入" }}
            </button>
            <p><a href="/auth/forgot-password">忘記密碼</a></p>
        </form>
    </div>
    <div v-else class="staff-shell">
        <aside class="rail">
            <div class="wordmark"><span class="mark">y</span> YACS</div>
            <select
                v-model="workspace"
                aria-label="工作空間"
                @change="run(switchWorkspace)"
            >
                <option v-for="w in workspaces" :key="w.id" :value="w.id">
                    {{ w.name }}
                </option>
            </select>
            <nav>
                <button
                    v-if="can('conversation.read')"
                    :class="{ active: tab === 'conversations' }"
                    @click="tab = 'conversations'"
                >
                    ◫ 對話收件匣</button
                ><button
                    v-if="can('knowledge.edit')"
                    :class="{ active: tab === 'knowledge' }"
                    @click="tab = 'knowledge'"
                >
                    ▤ 知識庫</button
                ><button
                    v-if="
                        permissions.some(
                            (p) => p.endsWith('.manage') || p === 'audit.read',
                        )
                    "
                    :class="{ active: tab === 'admin' }"
                    @click="tab = 'admin'"
                >
                    ⚙ 工作空間設定</button
                ><button
                    :class="{ active: tab === 'account' }"
                    @click="tab = 'account'"
                >
                    ◎ 帳號安全
                </button>
            </nav>
            <div class="rail-bottom">
                <label
                    >接案狀態<select v-model="presence" @change="setPresence">
                        <option value="offline">離線</option>
                        <option value="available">可接案</option>
                        <option value="busy">忙碌</option>
                    </select></label
                >
                <p>{{ user.name }}</p>
                <button @click="logout">登出</button>
            </div>
        </aside>
        <main class="workspace">
            <header class="topbar">
                <div>
                    <small
                        >工作空間 /
                        {{
                            workspaces.find((w) => w.id === workspace)?.name
                        }}</small
                    >
                    <h1>
                        {{
                            {
                                conversations: "對話收件匣",
                                knowledge: "知識庫",
                                admin: "工作空間設定",
                                account: "帳號安全",
                            }[tab]
                        }}
                    </h1>
                </div>
                <a href="/demo" target="_blank" rel="noopener">測試聊天 ↗</a>
            </header>
            <div v-if="error" class="error banner" role="alert">
                {{ error }}
                <button @click="error = ''" aria-label="關閉錯誤">×</button>
            </div>
            <div v-if="tab === 'conversations'" class="inbox-layout">
                <section class="conversation-list">
                    <div class="list-filter">
                        <input
                            v-model="query"
                            aria-label="搜尋對話"
                            placeholder="搜尋訊息內容"
                            @input="run(refreshList)"
                        /><select
                            v-model="filter"
                            aria-label="案件狀態"
                            @change="run(refreshList)"
                        >
                            <option value="">全部案件</option>
                            <option
                                v-for="s in [
                                    'open',
                                    'waiting_customer',
                                    'snoozed',
                                    'resolved',
                                ]"
                                :key="s"
                                :value="s"
                            >
                                {{ statusLabel[s] }}
                            </option>
                        </select>
                    </div>
                    <small class="count"
                        >{{ conversations.length }} 段對話</small
                    ><button
                        v-for="c in conversations"
                        :key="c.id"
                        :class="[
                            'conversation-row',
                            { selected: selected?.id === c.id },
                        ]"
                        @click="run(() => select(c))"
                    >
                        <span class="avatar">訪</span
                        ><span
                            ><strong>會員 {{ c.contact_id?.slice(-6) }}</strong
                            ><small
                                >{{ statusLabel[c.handling_mode] }} ·
                                {{ statusLabel[c.status] }}</small
                            ><small>{{ c.id.slice(-8) }}</small></span
                        ><span class="dot" :class="c.handling_mode"></span>
                    </button>
                    <p v-if="!conversations.length" class="empty">
                        目前沒有案件。<br />從測試聊天送出第一則訊息。
                    </p>
                </section>
                <section v-if="selected" class="conversation-detail">
                    <div class="detail-header">
                        <div>
                            <h2>會員 {{ selected.contact_id?.slice(-6) }}</h2>
                            <small
                                >{{ statusLabel[selected.status] }} ·
                                {{ statusLabel[selected.handling_mode] }}</small
                            >
                        </div>
                        <div class="actions">
                            <button
                                v-if="
                                    selected.handling_mode !== 'human' &&
                                    selected.status !== 'resolved'
                                "
                                :disabled="busy"
                                @click="action('claim')"
                            >
                                接手對話</button
                            ><button
                                v-if="selected.status === 'resolved'"
                                @click="action('reopen')"
                            >
                                重新開案</button
                            ><button
                                v-if="
                                    selected.assignee_id === membership &&
                                    selected.status !== 'resolved'
                                "
                                @click="action('release')"
                            >
                                釋出
                            </button>
                        </div>
                    </div>
                    <Thread :messages="messages" @download="download" />
                    <div v-if="aiDraft" class="ai-preview">
                        <strong>AI 建議草稿 · 請確認後使用</strong>
                        <p>{{ aiDraft }}</p>
                        <button
                            @click="
                                draft = aiDraft;
                                aiDraft = '';
                            "
                        >
                            放入草稿</button
                        ><button @click="aiDraft = ''">捨棄</button>
                    </div>
                    <form class="composer" @submit.prevent="send">
                        <div class="composer-tabs">
                            <button
                                type="button"
                                :class="{ active: !note }"
                                @click="note = false"
                            >
                                公開回覆</button
                            ><button
                                type="button"
                                :class="{ active: note }"
                                @click="note = true"
                            >
                                內部備註</button
                            ><select
                                v-if="macros.length"
                                v-model="macro"
                                aria-label="快捷回覆"
                                @change="applyMacro"
                            >
                                <option value="">快捷回覆</option>
                                <option
                                    v-for="m in macros.filter(
                                        (m) => m.status === 'active',
                                    )"
                                    :key="m.id"
                                    :value="m.id"
                                >
                                    {{ m.title }}
                                </option></select
                            ><span v-if="note">僅團隊可見</span>
                        </div>
                        <textarea
                            v-model="draft"
                            :class="{ note }"
                            :aria-label="note ? '內部備註' : '回覆內容'"
                            :placeholder="
                                note
                                    ? '寫下內部備註…'
                                    : '回覆會員…（Shift + Enter 換行）'
                            "
                            @keydown="
                                (e) => {
                                    if (sendOnEnter(e)) {
                                        e.preventDefault();
                                        send();
                                    }
                                }
                            "
                        ></textarea>
                        <div class="file-chips">
                            <button
                                v-for="a in attachments"
                                :key="a.id"
                                type="button"
                                @click="
                                    attachments = attachments.filter(
                                        (x) => x.id !== a.id,
                                    )
                                "
                            >
                                {{ a.name }} ×
                            </button>
                        </div>
                        <div class="composer-bottom">
                            <label class="file-button"
                                >＋ 附件<input
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp,application/pdf,text/plain"
                                    @change="addFile" /></label
                            ><button
                                type="button"
                                :disabled="busy || !!aiRun"
                                @click="draftAi"
                            >
                                {{ aiRun ? "AI 生成中…" : "✧ AI 草稿" }}</button
                            ><label class="inline"
                                ><input
                                    v-model="waiting"
                                    type="checkbox"
                                />等待會員</label
                            ><button
                                class="primary"
                                :disabled="
                                    busy ||
                                    (!note &&
                                        (selected.assignee_id !== membership ||
                                            selected.handling_mode !==
                                                'human' ||
                                            selected.status === 'resolved'))
                                "
                            >
                                {{ busy ? "處理中…" : "送出" }}
                            </button>
                        </div>
                    </form>
                </section>
                <section v-else class="conversation-detail empty-center">
                    <div>
                        <span class="empty-icon">◫</span>
                        <h2>選擇一段對話</h2>
                        <p>查看訊息、接手案件，或和團隊一起解決問題。</p>
                    </div>
                </section>
                <aside v-if="selected" class="context-panel">
                    <h3>案件資訊</h3>
                    <template v-if="contact"
                        ><label
                            >會員<span
                                >{{ contact.name }} ·
                                {{
                                    contact.identity_level === "verified"
                                        ? "已驗證"
                                        : "訪客"
                                }}</span
                            ></label
                        ><label v-if="contact.email"
                            >電子郵件<span>{{ contact.email }}</span></label
                        ><label
                            v-for="(value, key) in contact.attributes as Record<
                                string,
                                unknown
                            >"
                            :key="key"
                            >{{ key }}<span>{{ value }}</span></label
                        ></template
                    ><label v-if="tags.length"
                        >案件標籤<select
                            v-model="selectedTags"
                            multiple
                            aria-label="案件標籤"
                            @change="saveTags"
                        >
                            <option v-for="t in tags" :key="t.id" :value="t.id">
                                {{ t.name }}
                            </option>
                        </select></label
                    ><label
                        >案件編號<code>{{ selected.id }}</code></label
                    ><label
                        >負責人<span>{{
                            members.find((m) => m.id === selected?.assignee_id)
                                ?.display_name ??
                            (selected.assignee_id ? "客服人員" : "尚未指派")
                        }}</span></label
                    ><template
                        v-if="
                            can('conversation.assign') &&
                            selected.status !== 'resolved'
                        "
                        ><label
                            >轉派客服<select v-model="target">
                                <option value="">選擇客服</option>
                                <option
                                    v-for="m in members.filter(
                                        (m) => m.status === 'active',
                                    )"
                                    :key="m.id"
                                    :value="m.id"
                                >
                                    {{ m.display_name }}
                                </option>
                            </select></label
                        ><button
                            :disabled="!target || busy"
                            @click="action('assign', { assignee_id: target })"
                        >
                            轉派
                        </button></template
                    ><template v-if="selected.status !== 'resolved'"
                        ><label
                            >結案原因<select v-model="resolution">
                                <option
                                    v-for="r in reasons.filter(
                                        (r) => r.status === 'active',
                                    )"
                                    :key="r.id"
                                    :value="r.code"
                                >
                                    {{ r.label }}
                                </option>
                            </select></label
                        ><button
                            :disabled="
                                busy || selected.assignee_id !== membership
                            "
                            @click="
                                action('resolve', {
                                    resolution_code: resolution,
                                })
                            "
                        >
                            ✓ 結案</button
                        ><button
                            :disabled="
                                busy || selected.assignee_id !== membership
                            "
                            @click="
                                action('snooze', {
                                    wake_at: new Date(
                                        Date.now() + 3600000,
                                    ).toISOString(),
                                })
                            "
                        >
                            稍後處理（1 小時）</button
                        ><button
                            v-if="can('conversation.assign')"
                            @click="action('resume-ai')"
                        >
                            交回 AI
                        </button></template
                    >
                </aside>
            </div>
            <Knowledge
                v-else-if="tab === 'knowledge'"
                :key="workspace"
                :prefix="prefix"
                @error="error = $event"
            />
            <Admin
                v-else-if="tab === 'admin'"
                :key="workspace"
                :prefix="prefix"
                :permissions="permissions"
                @error="error = $event"
            />
            <section v-else class="content account">
                <h2>雙重驗證</h2>
                <p>目前：{{ user.mfa_enabled ? "已啟用" : "尚未啟用" }}</p>
                <form v-if="!user.mfa_enabled" @submit.prevent="setupMfa">
                    <label
                        >目前密碼<input
                            v-model="password"
                            type="password"
                            required
                            autocomplete="current-password" /></label
                    ><button class="primary" :disabled="busy">
                        設定驗證器
                    </button>
                </form>
                <form v-if="setup" @submit.prevent="confirmMfa">
                    <p>將金鑰加入驗證器，然後輸入六位數驗證碼。</p>
                    <code>{{ setup.secret }}</code
                    ><label
                        >驗證碼<input
                            v-model="code"
                            inputmode="numeric"
                            required /></label
                    ><button class="primary">確認啟用</button>
                </form>
                <div v-if="recovery.length">
                    <p>復原碼只顯示一次，請保存：</p>
                    <pre>{{ recovery.join("\n") }}</pre>
                </div>
            </section>
        </main>
    </div>
</template>
