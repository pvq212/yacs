<script setup lang="ts">
import { ref, onMounted, computed } from "vue";
import { api, errorText, statusLabel, upload, type Row } from "../client";
const props = defineProps<{ prefix: string }>(),
    emit = defineEmits<{ error: [message: string] }>();
const bases = ref<Row[]>([]),
    inboxes = ref<Row[]>([]),
    documents = ref<Row[]>([]),
    versions = ref<Row[]>([]),
    selected = ref<Row | null>(null),
    busy = ref(false),
    kb = ref(""),
    title = ref(""),
    body = ref(""),
    visibility = ref("staff_only"),
    file = ref<File | null>(null),
    question = ref(""),
    inbox = ref(""),
    results = ref<Record<string, unknown> | null>(null);
const kbName = ref(""),
    brand = ref(""),
    brands = ref<Row[]>([]),
    showBase = ref(false),
    showDocument = ref(false);
const prefix = computed(() => props.prefix);
async function run(fn: () => Promise<void>) {
    busy.value = true;
    emit("error", "");
    try {
        await fn();
    } catch (e) {
        emit("error", errorText(e));
    } finally {
        busy.value = false;
    }
}
async function load() {
    bases.value = await api<Row[]>(`${prefix.value}/knowledge-bases`);
    inboxes.value = await api<Row[]>(`${prefix.value}/inboxes`);
    documents.value = await api<Row[]>(`${prefix.value}/knowledge-documents`);
    kb.value ||= bases.value[0]?.id ?? "";
    inbox.value ||= inboxes.value[0]?.id ?? "";
    try {
        brands.value = await api<Row[]>(`${prefix.value}/brands`);
        brand.value ||= brands.value[0]?.id ?? "";
    } catch {
        brands.value = [];
    }
}
async function select(row: Row) {
    selected.value = row;
    versions.value = await api<Row[]>(
        `${prefix.value}/knowledge-documents/${row.id}/versions`,
    );
    body.value = "";
    file.value = null;
}
async function createBase() {
    await run(async () => {
        await api(`${prefix.value}/knowledge-bases`, "POST", {
            brand_id: brand.value,
            name: kbName.value,
            locale: "zh_TW",
            status: "active",
            inbox_ids: inbox.value ? [inbox.value] : [],
        });
        showBase.value = false;
        kbName.value = "";
        await load();
    });
}
async function createDoc() {
    await run(async () => {
        const doc = await api<Row>(
            `${prefix.value}/knowledge-documents`,
            "POST",
            {
                knowledge_base_id: kb.value,
                title: title.value,
                source_type: "faq",
            },
        );
        showDocument.value = false;
        title.value = "";
        await load();
        await select(doc);
    });
}
async function createVersion() {
    if (!selected.value) return;
    await run(async () => {
        const source = file.value
            ? {
                  source_file_id: await upload(
                      file.value,
                      prefix.value,
                      "knowledge",
                  ),
              }
            : { body_text: body.value };
        const version = await api<Row>(
            `${prefix.value}/knowledge-documents/${selected.value!.id}/versions`,
            "POST",
            { ...source, visibility: visibility.value, locale: "zh_TW" },
        );
        await api(
            `${prefix.value}/knowledge-versions/${version.id}/index`,
            "POST",
            {},
        );
        body.value = "";
        file.value = null;
        versions.value = await api<Row[]>(
            `${prefix.value}/knowledge-documents/${selected.value!.id}/versions`,
        );
    });
}
async function refreshVersions() {
    if (selected.value)
        versions.value = await api<Row[]>(
            `${prefix.value}/knowledge-documents/${selected.value.id}/versions`,
        );
}
async function publish(version: Row) {
    await run(async () => {
        selected.value = await api<Row>(
            `${prefix.value}/knowledge-versions/${version.id}/publish`,
            "POST",
            {
                expected_published_version_id:
                    selected.value?.published_version_id ?? null,
            },
        );
        await load();
        await refreshVersions();
    });
}
async function unpublish() {
    if (!selected.value) return;
    await run(async () => {
        await api(
            `${prefix.value}/knowledge-documents/${selected.value!.id}/unpublish`,
            "POST",
            {
                expected_published_version_id:
                    selected.value!.published_version_id ?? null,
            },
        );
        selected.value = { ...selected.value!, published_version_id: null };
        await load();
        await refreshVersions();
    });
}
async function search() {
    await run(async () => {
        results.value = await api(`${prefix.value}/knowledge-search`, "POST", {
            inbox_id: inbox.value,
            query: question.value,
            mode: "external_preview",
        });
    });
}
onMounted(() => run(load));
</script>
<template>
    <section class="content knowledge">
        <div class="section-title">
            <div>
                <h2>把團隊的答案，變成可重用的知識。</h2>
                <p>
                    新版本索引完成後才能發布。會員與 AI
                    只會看到已發布的公開知識。
                </p>
            </div>
            <div class="actions">
                <button @click="showBase = true">新增知識庫</button
                ><button
                    class="primary"
                    :disabled="!bases.length"
                    @click="showDocument = true"
                >
                    ＋ 新增文件
                </button>
            </div>
        </div>
        <div class="knowledge-grid">
            <aside class="document-list">
                <h3>知識文件</h3>
                <button
                    v-for="d in documents"
                    :key="d.id"
                    :class="{ active: selected?.id === d.id }"
                    @click="run(() => select(d))"
                >
                    <strong>{{ d.title }}</strong
                    ><small>{{
                        d.published_version_id ? "已發布" : "尚未發布"
                    }}</small>
                </button>
                <p v-if="!documents.length" class="empty">
                    先建立知識庫，再新增第一份文件。
                </p>
            </aside>
            <main class="knowledge-detail">
                <template v-if="selected"
                    ><div class="section-title">
                        <h2>{{ selected.title }}</h2>
                        <button
                            v-if="selected.published_version_id"
                            @click="unpublish"
                        >
                            撤下發布
                        </button>
                    </div>
                    <div class="versions">
                        <div
                            v-for="v in versions"
                            :key="v.id"
                            class="version-row"
                        >
                            <strong>版本 {{ v.version_number }}</strong
                            ><span
                                >{{ statusLabel[String(v.state)] ?? v.state }} ·
                                {{
                                    v.visibility === "external_answerable"
                                        ? "公開可回答"
                                        : "僅內部"
                                }}</span
                            ><button
                                v-if="v.state === 'ready'"
                                :disabled="busy"
                                @click="publish(v)"
                            >
                                發布
                            </button>
                            <details>
                                <summary>查看內容</summary>
                                <p>{{ v.body_text }}</p>
                            </details>
                        </div>
                        <button @click="run(refreshVersions)">
                            更新索引狀態
                        </button>
                    </div>
                    <form @submit.prevent="createVersion">
                        <h3>建立新版本</h3>
                        <label
                            >可見範圍<select v-model="visibility">
                                <option value="staff_only">僅內部使用</option>
                                <option value="external_answerable">
                                    公開可回答
                                </option>
                            </select></label
                        ><label
                            >內容<textarea
                                v-model="body"
                                rows="8"
                                placeholder="輸入 FAQ、Markdown 或純文字內容…"
                                :required="!file"
                            ></textarea></label
                        ><label
                            >或匯入 PDF／文字檔<input
                                type="file"
                                accept=".pdf,.txt,.md"
                                @change="
                                    file =
                                        ($event.target as HTMLInputElement)
                                            .files?.[0] ?? null
                                " /></label
                        ><button class="primary" :disabled="busy">
                            建立版本並索引
                        </button>
                    </form></template
                >
                <div v-else class="empty">選擇文件以查看版本與發布狀態。</div>
            </main>
        </div>
        <div class="search-preview">
            <h3>公開知識檢索測試</h3>
            <form class="search-form" @submit.prevent="search">
                <select v-model="inbox" aria-label="檢索收件匣">
                    <option v-for="i in inboxes" :key="i.id" :value="i.id">
                        {{ i.name }}
                    </option></select
                ><input
                    v-model="question"
                    placeholder="輸入會員可能提出的問題"
                    aria-label="檢索問題"
                    required
                /><button :disabled="busy">檢索</button>
            </form>
            <p v-if="results">
                {{ (results.sources as unknown[])?.length ?? 0 }} 筆來源 ·
                {{ results.retrieval_mode }}
            </p>
            <article
                v-for="s in (results?.sources as Record<string, unknown>[]) ??
                []"
                :key="String(s.chunk_id)"
                class="search-result"
            >
                <h4>{{ s.title }}</h4>
                <p>{{ s.text ?? s.snippet }}</p>
            </article>
        </div>
        <div
            v-if="showBase || showDocument"
            class="modal-backdrop"
            @click.self="
                showBase = false;
                showDocument = false;
            "
        >
            <section class="modal" role="dialog" aria-modal="true">
                <header>
                    <h2>{{ showBase ? "新增知識庫" : "新增文件" }}</h2>
                    <button
                        @click="
                            showBase = false;
                            showDocument = false;
                        "
                    >
                        ×
                    </button>
                </header>
                <form @submit.prevent="showBase ? createBase() : createDoc()">
                    <template v-if="showBase"
                        ><label
                            >品牌<select v-model="brand" required>
                                <option
                                    v-for="b in brands"
                                    :key="b.id"
                                    :value="b.id"
                                >
                                    {{ b.name }}
                                </option>
                            </select></label
                        ><label>名稱<input v-model="kbName" required /></label
                        ><label
                            >適用收件匣<select v-model="inbox">
                                <option
                                    v-for="i in inboxes"
                                    :key="i.id"
                                    :value="i.id"
                                >
                                    {{ i.name }}
                                </option>
                            </select></label
                        ></template
                    ><template v-else
                        ><label
                            >知識庫<select v-model="kb" required>
                                <option
                                    v-for="b in bases"
                                    :key="b.id"
                                    :value="b.id"
                                >
                                    {{ b.name }}
                                </option>
                            </select></label
                        ><label
                            >文件標題<input
                                v-model="title"
                                required /></label></template
                    ><button class="primary" :disabled="busy">建立</button>
                </form>
            </section>
        </div>
    </section>
</template>
