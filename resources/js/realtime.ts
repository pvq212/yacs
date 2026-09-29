import Pusher from "pusher-js";
import { api } from "./client";
export function subscribe(
    sessionId: string,
    conversationId: string,
    staff: boolean,
    prefix: string,
    changed: () => void,
    token?: string,
): () => void {
    const key = document.querySelector<HTMLMetaElement>(
        "meta[name=yacs-reverb-key]",
    )?.content;
    if (!key || !sessionId) return () => {};
    const host =
        document.querySelector<HTMLMetaElement>("meta[name=yacs-reverb-host]")
            ?.content || location.hostname;
    const port = Number(
        document.querySelector<HTMLMetaElement>("meta[name=yacs-reverb-port]")
            ?.content || 8080,
    );
    const tls =
        document.querySelector<HTMLMetaElement>("meta[name=yacs-reverb-tls]")
            ?.content === "true";
    const socket = new Pusher(key, {
        cluster: "mt1",
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS: tls,
        enabledTransports: ["ws", "wss"],
        disableStats: true,
        channelAuthorization: {
            customHandler: (params, callback) => {
                api<{ auth: string }>(
                    `${prefix}/broadcasting/auth`,
                    "POST",
                    {
                        socket_id: params.socketId,
                        channel_name: params.channelName,
                    },
                    token,
                )
                    .then((result) => callback(null, result))
                    .catch((e) => callback(e, null));
            },
        },
    });
    const channel = socket.subscribe(
        `private-${staff ? "staff" : "visitor"}.session.${sessionId}.conversation.${conversationId}`,
    );
    channel.bind("yacs.event", changed);
    return () => {
        channel.unbind_all();
        socket.disconnect();
    };
}
