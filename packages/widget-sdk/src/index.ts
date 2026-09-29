/** MIT 授權。宿主只接收事件，不會取得訪客 access/refresh token。 */
export type InitOptions = {
    baseUrl: string;
    inboxKey: string;
    locale?: string;
};
export type WidgetEvent =
    | "ready"
    | "opened"
    | "closed"
    | "error"
    | "unread"
    | "message"
    | "identified"
    | "logged-out";
type Listener = (data: unknown) => void;
let frame: HTMLIFrameElement | undefined,
    bubble: HTMLButtonElement | undefined,
    origin = "",
    ready = false;
const queue: { command: string; payload?: unknown }[] = [],
    listeners = new Map<string, Set<Listener>>();
const pending = new Map<
    string,
    {
        resolve: (value: unknown) => void;
        reject: (error: Error) => void;
        timer: ReturnType<typeof setTimeout>;
    }
>();
function emit(name: string, data?: unknown) {
    listeners.get(name)?.forEach((fn) => fn(data));
}
function receive(event: MessageEvent) {
    if (
        event.origin !== origin ||
        event.source !== frame?.contentWindow ||
        event.data?.source !== "yacs-widget"
    )
        return;
    const { type, data, id, error } = event.data;
    if (type === "ready") {
        ready = true;
        queue.splice(0).forEach((c) => send(c.command, c.payload));
    }
    if (type === "response" && pending.has(id)) {
        const p = pending.get(id)!;
        clearTimeout(p.timer);
        pending.delete(id);
        if (error) p.reject(new Error(error));
        else p.resolve(data);
        return;
    }
    if (type === "close-request") {
        Yacs.close();
        return;
    }
    emit(type, data);
}
function send(command: string, payload?: unknown) {
    if (!frame) throw new Error("請先呼叫 Yacs.init()");
    if (!ready) {
        if (queue.length >= 50) throw new Error("客服元件指令佇列已滿");
        queue.push({ command, payload });
        return;
    }
    frame.contentWindow?.postMessage(
        { source: "yacs-host", command, payload },
        origin,
    );
}
function request(command: string, payload?: unknown): Promise<unknown> {
    if (!frame) return Promise.reject(new Error("請先呼叫 Yacs.init()"));
    const id = crypto.randomUUID();
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => {
            pending.delete(id);
            reject(new Error("客服元件回應逾時"));
        }, 15000);
        pending.set(id, { resolve, reject, timer });
        if (!ready) {
            const cancel = Yacs.on("ready", () => {
                cancel();
                frame?.contentWindow?.postMessage(
                    { source: "yacs-host", command, payload, id },
                    origin,
                );
            });
        } else
            frame?.contentWindow?.postMessage(
                { source: "yacs-host", command, payload, id },
                origin,
            );
    });
}
export const Yacs = {
    init(options: InitOptions) {
        if (frame) return;
        const base = new URL(options.baseUrl);
        if (
            !["https:", "http:"].includes(base.protocol) ||
            base.username ||
            base.password
        )
            throw new Error("無效的客服網址");
        origin = base.origin;
        ready = false;
        frame = document.createElement("iframe");
        frame.title = "聯絡客服";
        frame.src = `${origin}/widget/${encodeURIComponent(options.inboxKey)}?parent_origin=${encodeURIComponent(location.origin)}&locale=${encodeURIComponent(options.locale ?? "zh_TW")}`;
        frame.style.cssText =
            "position:fixed;bottom:88px;right:24px;width:min(390px,calc(100vw - 32px));height:min(620px,calc(100dvh - 120px));border:0;border-radius:20px;box-shadow:0 16px 64px #12234533;z-index:2147483646;display:none;background:white";
        frame.setAttribute("allow", "");
        bubble = document.createElement("button");
        bubble.type = "button";
        bubble.textContent = "◌ 聯絡客服";
        bubble.setAttribute("aria-expanded", "false");
        bubble.style.cssText =
            "position:fixed;bottom:24px;right:24px;background:#425bd4;color:white;border:0;border-radius:28px;padding:16px 24px;box-shadow:0 8px 24px #17233b33;font:600 16px system-ui;cursor:pointer;z-index:2147483647";
        bubble.onclick = () =>
            frame && frame.style.display === "none"
                ? Yacs.open()
                : Yacs.close();
        window.addEventListener("message", receive);
        document.body.append(frame, bubble);
    },
    open() {
        if (!frame) throw new Error("請先初始化");
        frame.style.display = "block";
        bubble?.setAttribute("aria-expanded", "true");
        send("open");
        emit("opened");
    },
    close() {
        if (!frame) return;
        frame.style.display = "none";
        bubble?.setAttribute("aria-expanded", "false");
        send("close");
        emit("closed");
    },
    identify(assertion: string) {
        return request("identify", { assertion });
    },
    logout() {
        return request("logout");
    },
    setContext(context: Record<string, unknown>) {
        return request("context", context);
    },
    setAttributes(attributes: Record<string, unknown>) {
        return request("attributes", { attributes });
    },
    sendMessage(text: string) {
        return request("send", { text });
    },
    on(event: WidgetEvent, listener: Listener) {
        if (!listeners.has(event)) listeners.set(event, new Set());
        listeners.get(event)!.add(listener);
        return () => {
            listeners.get(event)?.delete(listener);
        };
    },
    async destroy() {
        try {
            if (frame) await request("destroy");
        } finally {
            frame?.remove();
            bubble?.remove();
            frame = undefined;
            bubble = undefined;
            ready = false;
            queue.length = 0;
            window.removeEventListener("message", receive);
            pending.forEach((p) => {
                clearTimeout(p.timer);
                p.reject(new Error("客服元件已移除"));
            });
            pending.clear();
            listeners.clear();
        }
    },
};
declare global {
    interface Window {
        Yacs: typeof Yacs;
    }
}
window.Yacs = Yacs;
