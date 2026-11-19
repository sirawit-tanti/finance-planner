import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import { VitePWA } from "vite-plugin-pwa";

export default defineConfig({
  plugins: [
    react(),
    tailwindcss(),
    VitePWA({
      // We write our own service worker (src/sw.ts) to control sync behaviour.
      strategies: "injectManifest",
      srcDir: "src",
      filename: "sw.ts",
      // 'prompt': a new version waits until the user agrees, so a form being
      // filled in is never wiped by a silent reload.
      registerType: "prompt",
      injectRegister: false, // registration is done explicitly in main.tsx
      manifest: {
        name: "Finance Planner - วางแผนการเงิน",
        short_name: "Finance",
        description:
          "วางแผนกระแสเงินสด รายรับ รายจ่าย บัตรเครดิต และการผ่อนชำระ",
        lang: "th",
        display: "standalone",
        start_url: "/",
        scope: "/",
        theme_color: "#0f766e",
        background_color: "#ffffff",
        icons: [
          { src: "pwa-192x192.png", sizes: "192x192", type: "image/png" },
          { src: "pwa-512x512.png", sizes: "512x512", type: "image/png" },
          {
            src: "pwa-maskable-512x512.png",
            sizes: "512x512",
            type: "image/png",
            purpose: "maskable",
          },
        ],
      },
      injectManifest: {
        // App shell only. API responses are deliberately NOT cached:
        // financial data lives in IndexedDB, never in the browser cache.
        globPatterns: ["**/*.{js,css,html,svg,png,ico,woff2}"],
      },
      devOptions: { enabled: true, type: "module" },
    }),
  ],
  test: {
    environment: "jsdom",
    globals: true,
    setupFiles: "./src/test/setup.ts",
    env: { VITE_API_URL: "http://localhost:8000" },
  },
});
