import { createApp } from "vue";
import Staff from "./components/Staff.vue";
import Auth from "./components/Auth.vue";
import Widget from "./components/Widget.vue";
createApp(
    location.pathname.startsWith("/widget/")
        ? Widget
        : location.pathname.startsWith("/auth/")
          ? Auth
          : Staff,
).mount("#app");
