import { defineConfig } from "vite";
export default defineConfig({
    publicDir: false,
    build: {
        outDir: "public/sdk",
        emptyOutDir: true,
        lib: {
            entry: "packages/widget-sdk/src/index.ts",
            name: "YacsSDK",
            formats: ["iife"],
            fileName: () => "yacs.js",
        },
    },
});
