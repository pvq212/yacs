<script setup lang="ts">
import type { Message } from "../client";
defineProps<{ messages: Message[] }>();
defineEmits<{ download: [id: string] }>();
</script>
<template>
    <div class="thread" role="log" aria-label="對話訊息" aria-live="polite">
        <p v-if="!messages.length" class="empty">從一則訊息開始。</p>
        <article
            v-for="m in messages"
            :key="m.id"
            :class="[
                'message',
                m.author_type,
                { note: m.visibility === 'internal' },
            ]"
        >
            <div class="message-meta">
                {{ m.visibility === "internal" ? "內部備註 · " : ""
                }}{{
                    {
                        visitor: "會員",
                        staff: "客服",
                        ai: "AI 助理",
                        system: "系統",
                    }[m.author_type] ?? m.author_type
                }}
                <time>{{
                    new Date(m.created_at).toLocaleTimeString("zh-TW", {
                        hour: "2-digit",
                        minute: "2-digit",
                    })
                }}</time>
            </div>
            <p>{{ m.body_text }}</p>
            <small v-for="d in m.deliveries" :key="d.id"
                >渠道：{{
                    {
                        pending: "等待傳送",
                        sending: "傳送中",
                        sent: "已送出",
                        delivered: "已送達",
                        read: "已讀",
                        failed: "傳送失敗",
                        unknown: "狀態待確認",
                    }[d.state]
                }}</small
            ><button
                v-for="a in m.attachments"
                :key="a.id"
                class="attachment"
                @click="$emit('download', a.id)"
            >
                ↧ {{ a.name }}</button
            ><small v-if="m.citations?.length"
                >參考：<span v-for="s in m.citations" :key="s.label"
                    >{{ s.title || s.label }}
                </span></small
            >
        </article>
    </div>
</template>
