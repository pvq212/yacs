<script setup lang="ts">
import { ref, onMounted, onUnmounted, nextTick } from "vue";
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
} from "../client";
import Thread from "./Thread.vue";
import { subscribe } from "../realtime";
type Session = {
    visitor_session_id: string;
    access_token: string;
    refresh_token: string;
    expires_at: string;
    identity_level: string;
};
const key = decodeURIComponent(location.pathname.split("/").pop() ?? ""),
    parentOrigin =
        new URLSearchParams(location.search).get("parent_origin") ??
        location.origin,
    storageKey = `yacs-session-${key}-${parentOrigin}`;
const session = ref<Session | null>(null),
    conversation = ref<Conversation | null>(null),
    history = ref<Conversation[]>([]),
    messages = ref<Message[]>([]),
    faqs = ref<{ question: string; answer: string }[]>([]),
    draft = ref(""),
    files = ref<{ id: string; name: string }[]>([]),
    error = ref(""),
    busy = ref(false),
    loading = ref(true),
    title = ref("聯絡客服"),
    online = ref(false),
    activeTab = ref("chat"),
    feedback = ref(false);
let disconnect = () => {},
    subscribed = "";
function connect() {
    const id = conversation.value?.id;
    if (!id || !session.value) {
        disconnect();
        subscribed = "";
        return;
    }
    const name =
        session.value.visitor_session_id + id + session.value.access_token;
    if (name !== subscribed) {
        disconnect();
        subscribed = name;
        disconnect = subscribe(
            session.value.visitor_session_id,
            id,
            false,
            "widget",
            () => snapshot().catch(() => {}),
            session.value.access_token,
        );
    }
}
let timer: ReturnType<typeof setInterval> | undefined,
    refreshPromise: Promise<void> | undefined,
    polling = false,
    pendingId = "",
    pendingBody = "",
    lastMessage = "",
    destroyed = false;
function post(type: string, data?: unknown, id?: string, err?: string) {
    if (window.parent !== window)
        window.parent.postMessage(
            { source: "yacs-widget", type, data, id, error: err },
            parentOrigin,
        );
}
function readSession() {
    try {
        const value = localStorage.getItem(storageKey);
        return value ? (JSON.parse(value) as Session) : null;
    } catch {
        return null;
    }
}
function saveSession(value: Session | null) {
    session.value = value;
    try {
        if (value) localStorage.setItem(storageKey, JSON.stringify(value));
        else localStorage.removeItem(storageKey);
    } catch {
        /* 第三方儲存不可用时仍使用記憶體 session。 */
    }
}
async function refresh() {
    if (refreshPromise) return refreshPromise;
    const operation = async () => {
        const saved = readSession();
        if (
            saved &&
            saved.visitor_session_id === session.value?.visitor_session_id &&
            Date.parse(saved.expires_at) > Date.now() + 30000
        ) {
            session.value = saved;
            return;
        }
        if (!session.value) return;
        saveSession(
            await api<Session>("widget/token/refresh", "POST", {
                refresh_token: session.value.refresh_token,
            }),
        );
    };
    refreshPromise = (async () => {
        if ("locks" in navigator)
            await navigator.locks.request<void>(storageKey, operation);
        else await operation();
    })().finally(() => {
        refreshPromise = undefined;
    });
    return refreshPromise;
}
async function request<T>(
    path: string,
    method = "GET",
    body?: unknown,
): Promise<T> {
    if (!session.value) throw new Error("客服尚未連線");
    if (Date.parse(session.value.expires_at) < Date.now() + 30000)
        await refresh();
    return api<T>(`widget/${path}`, method, body, session.value!.access_token);
}
async function run(fn: () => Promise<void>) {
    error.value = "";
    busy.value = true;
    try {
        await fn();
    } catch (e) {
        error.value = errorText(e);
        post("error", { message: error.value });
    } finally {
        busy.value = false;
    }
}
async function bootstrap() {
    loading.value = true;
    try {
        saveSession(readSession());
        if (session.value) {
            try {
                await refresh();
                await load();
            } catch {
                saveSession(null);
            }
        }
        if (!session.value) {
            const result = await api<{
                session: Session;
                theme: { display_name: string; primary_color: string };
                inbox_status: string;
            }>("widget/bootstrap", "POST", {
                inbox_key: key,
                parent_origin: parentOrigin,
            });
            saveSession(result.session);
            title.value = result.theme.display_name;
            online.value = result.inbox_status === "online";
            document.documentElement.style.setProperty(
                "--accent",
                result.theme.primary_color,
            );
            await load();
        }
        post("ready");
    } finally {
        loading.value = false;
    }
}
async function load() {
    history.value = await request<Conversation[]>("conversations");
    faqs.value = await request<typeof faqs.value>("faqs");
    conversation.value = history.value[0] ?? null;
    if (conversation.value) await snapshot();
    try {
        draft.value =
            localStorage.getItem(
                `yacs-draft-${key}-${session.value!.visitor_session_id}`,
            ) ?? "";
    } catch {}
}
async function snapshot() {
    connect();
    const id = conversation.value?.id;
    if (!id) return;
    const data = await request<Snapshot>(`conversations/${id}/snapshot`);
    if (conversation.value?.id !== id) return;
    conversation.value = data.conversation;
    history.value = [
        data.conversation,
        ...history.value.filter((c) => c.id !== id),
    ];
    messages.value = data.messages;
    const latest = data.messages.at(-1);
    if (
        latest &&
        latest.id !== lastMessage &&
        latest.author_type !== "visitor"
    ) {
        post("message", { id: latest.id, author_type: latest.author_type });
    }
    lastMessage = latest?.id ?? "";
    await nextTick();
    const el = document.querySelector(".thread");
    if (el) el.scrollTop = el.scrollHeight;
}
async function send() {
    if (busy.value || (!draft.value.trim() && !files.value.length)) return;
    await run(async () => {
        if (!conversation.value)
            conversation.value = await request<Conversation>(
                "conversations",
                "POST",
                {},
            );
        const content = JSON.stringify([draft.value, files.value]);
        if (content !== pendingBody) {
            pendingId = crypto.randomUUID();
            pendingBody = content;
        }
        const body = {
            client_message_id: pendingId,
            body_text: draft.value,
            attachment_ids: files.value.map((f) => f.id),
        };
        try {
            await request(
                `conversations/${conversation.value.id}/messages`,
                "POST",
                body,
            );
        } catch (e) {
            if (
                e instanceof ApiError &&
                e.code === "NEW_CONVERSATION_REQUIRED"
            ) {
                conversation.value = await request("conversations", "POST", {
                    related_conversation_id: conversation.value.id,
                });
                await request(
                    `conversations/${conversation.value!.id}/messages`,
                    "POST",
                    body,
                );
            } else throw e;
        }
        draft.value = "";
        files.value = [];
        pendingBody = "";
        feedback.value = false;
        persistDraft();
        await snapshot();
    });
}
function persistDraft() {
    try {
        if (session.value)
            localStorage.setItem(
                `yacs-draft-${key}-${session.value.visitor_session_id}`,
                draft.value,
            );
    } catch {}
}
async function handoff() {
    await run(async () => {
        if (!conversation.value)
            conversation.value = await request("conversations", "POST", {});
        conversation.value = await request(
            `conversations/${conversation.value!.id}/handoff`,
            "POST",
            {},
        );
    });
}
async function solved() {
    if (!conversation.value) return;
    await run(async () => {
        conversation.value = await request(
            `conversations/${conversation.value!.id}/resolve`,
            "POST",
            { confirmed_solved: true },
        );
    });
}
async function rate(score: number) {
    if (!conversation.value) return;
    await run(async () => {
        await request(`conversations/${conversation.value!.id}/csat`, "POST", {
            resolution_cycle: conversation.value!.resolution_cycle,
            score,
        });
        feedback.value = true;
    });
}
async function addFile(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (file)
        await run(async () => {
            if (Date.parse(session.value!.expires_at) < Date.now() + 30000)
                await refresh();
            files.value.push({
                id: await upload(
                    file,
                    "widget",
                    "chat",
                    session.value!.access_token,
                ),
                name: file.name,
            });
        });
    (e.target as HTMLInputElement).value = "";
}
async function download(id: string) {
    await run(async () => {
        const link = await request<{ url: string }>(`files/${id}/download`);
        window.open(link.url, "_blank", "noopener");
    });
}
async function reset(restart = true) {
    disconnect();
    subscribed = "";
    if (session.value) {
        await request("logout", "POST", {});
        try {
            localStorage.removeItem(
                `yacs-draft-${key}-${session.value.visitor_session_id}`,
            );
        } catch {}
    }
    saveSession(null);
    conversation.value = null;
    messages.value = [];
    history.value = [];
    draft.value = "";
    files.value = [];
    if (restart) await bootstrap();
    else destroyed = true;
    post("logged-out");
}
async function receive(e: MessageEvent) {
    if (
        e.source !== parent ||
        e.origin !== parentOrigin ||
        e.data?.source !== "yacs-host"
    )
        return;
    const { command, payload, id } = e.data;
    try {
        if (command === "identify") {
            const identified = await api<Session>(
                "widget/identity",
                "POST",
                { inbox_key: key, assertion: payload.assertion },
                session.value?.access_token,
            );
            saveSession(identified);
            conversation.value = null;
            messages.value = [];
            draft.value = "";
            files.value = [];
            await load();
            post("identified");
        } else if (command === "logout") await reset();
        else if (command === "destroy") await reset(false);
        else if (command === "context")
            await request("context", "PUT", payload);
        else if (command === "attributes")
            await request("attributes", "PUT", payload);
        else if (command === "send") {
            draft.value = String(payload.text);
            await send();
            if (error.value) throw new Error(error.value);
        } else if (command === "close") persistDraft();
        if (id) post("response", { ok: true }, id);
    } catch (err) {
        post("response", null, id, errorText(err));
    }
}
async function tick() {
    if (polling || busy.value || !session.value || destroyed) return;
    polling = true;
    try {
        await snapshot();
    } catch (e) {
        error.value = errorText(e);
    } finally {
        polling = false;
    }
}
onMounted(() => {
    window.addEventListener("message", receive);
    run(bootstrap);
    timer = setInterval(tick, 3000);
});
onUnmounted(() => {
    destroyed = true;
    clearInterval(timer);
    disconnect();
    persistDraft();
    window.removeEventListener("message", receive);
});
</script>
<template>
    <div class="widget-shell">
        <header class="widget-header">
            <div>
                <h1>{{ title }}</h1>
                <small
                    ><span class="online-dot" :class="{ online }"></span
                    >{{
                        online ? "團隊在線上" : "留下訊息，我們會盡快回覆"
                    }}</small
                >
            </div>
            <button aria-label="關閉客服" @click="post('close-request')">
                ×
            </button>
        </header>
        <nav class="widget-tabs">
            <button
                :class="{ active: activeTab === 'chat' }"
                @click="activeTab = 'chat'"
            >
                對話</button
            ><button
                :class="{ active: activeTab === 'faq' }"
                @click="activeTab = 'faq'"
            >
                常見問題</button
            ><button
                :class="{ active: activeTab === 'history' }"
                @click="activeTab = 'history'"
            >
                歷史紀錄
            </button>
        </nav>
        <p v-if="error" class="error" role="alert">
            {{ error }} <button @click="error = ''">×</button>
        </p>
        <p v-if="loading" class="empty">正在連線…</p>
        <template v-else-if="activeTab === 'chat'"
            ><div class="widget-state" v-if="conversation">
                {{ statusLabel[conversation.handling_mode] }} ·
                {{ statusLabel[conversation.status] }}
            </div>
            <div v-if="!messages.length" class="widget-welcome">
                <span class="welcome-icon">✦</span>
                <h2>您好，有什麼可以幫忙的？</h2>
                <p>告訴我們您的問題，我們一起找答案。</p>
            </div>
            <Thread :messages="messages" @download="download" />
            <div
                v-if="conversation?.status === 'resolved' && !feedback"
                class="csat"
            >
                <p>這次服務有幫助嗎？</p>
                <button
                    v-for="score in 5"
                    :key="score"
                    :aria-label="`${score} 分`"
                    @click="rate(score)"
                >
                    {{ score }} ★
                </button>
            </div>
            <p v-if="feedback" class="widget-state">謝謝您的回饋！</p>
            <div class="widget-actions">
                <button :disabled="busy" @click="handoff">轉接真人</button
                ><button
                    v-if="conversation && conversation.status !== 'resolved'"
                    :disabled="busy"
                    @click="solved"
                >
                    問題已解決
                </button>
            </div>
            <form class="widget-composer" @submit.prevent="send">
                <textarea
                    v-model="draft"
                    aria-label="您的訊息"
                    placeholder="輸入訊息…"
                    :disabled="loading"
                    @input="persistDraft"
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
                        v-for="f in files"
                        :key="f.id"
                        type="button"
                        @click="files = files.filter((a) => a.id !== f.id)"
                    >
                        {{ f.name }} ×
                    </button>
                </div>
                <div>
                    <label class="file-button" aria-label="上傳附件"
                        >＋ 附件<input
                            type="file"
                            accept="image/png,image/jpeg,image/webp,application/pdf,text/plain"
                            @change="addFile" /></label
                    ><button
                        class="primary"
                        :disabled="
                            busy || loading || (!draft.trim() && !files.length)
                        "
                    >
                        送出 ↑
                    </button>
                </div>
            </form></template
        >
        <section v-else-if="activeTab === 'faq'" class="widget-faq">
            <details v-for="faq in faqs" :key="faq.question">
                <summary>{{ faq.question }}</summary>
                <p>{{ faq.answer }}</p>
            </details>
            <p v-if="!faqs.length" class="empty">
                還沒有常見問題，您可以直接傳訊息給我們。
            </p>
        </section>
        <section v-else class="widget-history">
            <button
                v-for="c in history"
                :key="c.id"
                @click="
                    run(async () => {
                        conversation = c;
                        await snapshot();
                        activeTab = 'chat';
                    })
                "
            >
                <strong>對話 {{ c.id.slice(-6) }}</strong
                ><small>{{ statusLabel[c.status] }}</small></button
            ><button
                @click="
                    conversation = null;
                    messages = [];
                    activeTab = 'chat';
                "
            >
                ＋ 開始新對話
            </button>
        </section>
        <footer class="widget-footer">
            由 YACS 提供客服服務 ·
            <a
                href="https://github.com/pvq212/yacs"
                target="_blank"
                rel="noopener"
                >原始碼</a
            >
        </footer>
    </div>
</template>
