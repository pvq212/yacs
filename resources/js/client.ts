import type { components } from "./generated/api";
export class ApiError extends Error {
    constructor(
        public status: number,
        public code: string,
        message: string,
        public requestId?: string,
    ) {
        super(message);
    }
}
export type Envelope<T> = {
    data: T;
    meta: {
        request_id: string;
        next_cursor?: string | null;
        has_more?: boolean;
    };
};
let csrfReady = false;
export async function api<T>(
    path: string,
    method = "GET",
    body?: unknown,
    token?: string,
    key = crypto.randomUUID(),
): Promise<T> {
    if (
        !path.startsWith("widget/") &&
        !token &&
        method !== "GET" &&
        !csrfReady
    ) {
        await fetch("/api/v1/auth/csrf-cookie", { credentials: "same-origin" });
        csrfReady = true;
    }
    const headers: Record<string, string> = {
        Accept: "application/json",
        "Accept-Language": "zh-TW",
    };
    if (body !== undefined) headers["Content-Type"] = "application/json";
    if (method !== "GET") headers["Idempotency-Key"] = key;
    if (token) headers.Authorization = `Bearer ${token}`;
    else {
        const cookie = document.cookie
            .split("; ")
            .find((c) => c.startsWith("XSRF-TOKEN="));
        if (cookie)
            headers["X-XSRF-TOKEN"] = decodeURIComponent(cookie.slice(11));
    }
    const response = await fetch(`/api/v1/${path}`, {
        method,
        headers,
        body: body === undefined ? undefined : JSON.stringify(body),
        credentials:
            token || path.startsWith("widget/") ? "omit" : "same-origin",
    });
    if (response.status === 204) return undefined as T;
    const payload = await response.json();
    if (!response.ok)
        throw new ApiError(
            response.status,
            payload.error?.code ?? "HTTP_ERROR",
            payload.error?.message ?? "請稍後再試",
            payload.error?.request_id,
        );
    return payload.data as T;
}
export type Conversation = components["schemas"]["PublicConversation"] &
    Partial<
        Omit<
            components["schemas"]["StaffConversation"],
            keyof components["schemas"]["PublicConversation"]
        >
    >;
export type Attachment = components["schemas"]["Attachment"];
export type Message = components["schemas"]["PublicMessage"] &
    Partial<
        Omit<
            components["schemas"]["StaffMessage"],
            keyof components["schemas"]["PublicMessage"]
        >
    >;
export type Snapshot = {
    conversation: Conversation;
    messages: Message[];
    last_event_seq: string;
    older_messages_cursor: string | null;
};
export type Row = Record<string, unknown> & {
    id: string;
    name?: string;
    title?: string;
    version?: string;
};
export const statusLabel: Record<string, string> = {
    open: "處理中",
    waiting_customer: "等候會員",
    snoozed: "稍後處理",
    resolved: "已結案",
    ai: "AI 服務",
    human_queue: "等待接手",
    human: "人工服務",
    running: "執行中",
    succeeded: "已完成",
    pending: "待處理",
    failed: "失敗",
    draft: "草稿",
    ready: "可發布",
    published: "已發布",
    indexing: "索引中",
};
export const errorText = (e: unknown): string =>
    e instanceof ApiError
        ? e.status === 409
            ? "案件已由其他人更新；草稿已保留，請確認最新狀態後重試。"
            : `${e.message}（${e.code}）`
        : e instanceof Error
          ? e.message
          : "目前無法連線，請稍後重試。";
export function compareSequence(a: string, b: string): number {
    return BigInt(a) < BigInt(b) ? -1 : BigInt(a) > BigInt(b) ? 1 : 0;
}
export function sendOnEnter(event: KeyboardEvent): boolean {
    return (
        event.key === "Enter" &&
        !event.shiftKey &&
        !event.isComposing &&
        event.keyCode !== 229
    );
}
export async function upload(
    file: File,
    prefix: string,
    purpose = "chat",
    token?: string,
): Promise<string> {
    const ticket = await api<{
        file_id: string;
        upload_url: string;
        method: string;
        headers: Record<string, string>;
    }>(
        `${prefix}/files/uploads`,
        "POST",
        {
            filename: file.name,
            content_type: file.type || "text/plain",
            bytes: file.size,
            purpose,
        },
        token,
    );
    const response = await fetch(ticket.upload_url, {
        method: ticket.method,
        headers: ticket.headers,
        body: file,
    });
    if (!response.ok) throw new Error("附件上傳失敗，請重試。");
    let completed = await api<{ state: string }>(
        `${prefix}/files/${ticket.file_id}/complete`,
        "POST",
        {},
        token,
    );
    for (
        let attempt = 0;
        completed.state === "quarantined" && attempt < 30;
        attempt++
    ) {
        await new Promise((resolve) => setTimeout(resolve, 1000));
        completed = await api<{ state: string }>(
            `${prefix}/files/${ticket.file_id}`,
            "GET",
            undefined,
            token,
        );
    }
    if (completed.state !== "clean") throw new Error("附件內容驗證未通過。");
    return ticket.file_id;
}
