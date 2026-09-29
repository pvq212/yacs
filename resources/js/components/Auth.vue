<script setup lang="ts">
import { ref, onMounted } from "vue";
import { api, errorText } from "../client";
const invitation = location.pathname.endsWith("accept-invitation"),
    forgot = location.pathname.endsWith("forgot-password");
const token = new URLSearchParams(location.hash.slice(1)).get("token") ?? "";
history.replaceState(null, "", location.pathname);
const email = ref(""),
    password = ref(""),
    name = ref(""),
    requiresPassword = ref(true),
    workspace = ref(""),
    error = ref(""),
    done = ref(false),
    busy = ref(false);
onMounted(async () => {
    if (invitation) {
        try {
            const info = await api<{
                email: string;
                workspace_name: string;
                requires_password: boolean;
            }>("auth/invitations/lookup", "POST", { token });
            email.value = info.email;
            workspace.value = info.workspace_name;
            requiresPassword.value = info.requires_password;
        } catch (e) {
            error.value = errorText(e);
        }
    }
});
async function submit() {
    busy.value = true;
    error.value = "";
    try {
        if (forgot)
            await api("auth/forgot-password", "POST", { email: email.value });
        else if (invitation)
            await api("auth/invitations/accept", "POST", {
                token,
                ...(requiresPassword.value ? { password: password.value } : {}),
                ...(name.value ? { name: name.value } : {}),
            });
        else
            await api("auth/reset-password", "POST", {
                token,
                email: email.value,
                password: password.value,
            });
        done.value = true;
        password.value = "";
    } catch (e) {
        error.value = errorText(e);
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <main class="login-page">
        <div class="login-brand">
            <span class="mark">y</span>
            <h1>YACS 客服中心</h1>
            <p>安全地加入團隊，繼續每一段對話。</p>
        </div>
        <section class="login-card">
            <h1>
                {{
                    invitation
                        ? "加入客服團隊"
                        : forgot
                          ? "找回密碼"
                          : "設定新密碼"
                }}
            </h1>
            <p v-if="workspace">{{ workspace }}</p>
            <p v-if="error" class="error" role="alert">{{ error }}</p>
            <p v-if="done">
                {{
                    forgot
                        ? "若此帳號存在，我們已寄出重設連結。"
                        : "已完成，您現在可以登入。"
                }}
            </p>
            <form v-else @submit.prevent="submit">
                <label
                    >電子郵件<input
                        v-model="email"
                        type="email"
                        :readonly="invitation"
                        required
                        autocomplete="email" /></label
                ><label v-if="invitation"
                    >顯示名稱<input v-model="name" autocomplete="name" /></label
                ><label v-if="!forgot && requiresPassword"
                    >新密碼<input
                        v-model="password"
                        type="password"
                        minlength="12"
                        required
                        autocomplete="new-password" /></label
                ><button class="primary" :disabled="busy">
                    {{
                        invitation
                            ? "接受邀請"
                            : forgot
                              ? "寄送重設連結"
                              : "重設密碼"
                    }}
                </button>
            </form>
            <a href="/agent">返回登入</a>
        </section>
    </main>
</template>
