import { defineConfig } from "@playwright/test";
export default defineConfig({
    testDir: "tests/browser",
    timeout: 45000,
    use: {
        baseURL: "http://localhost:8000",
        trace: "retain-on-failure",
        screenshot: "only-on-failure",
    },
    workers: 1,
    reporter: [["list"]],
});
