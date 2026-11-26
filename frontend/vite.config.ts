import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

// Dev server proxies /api to the Laravel backend (default http://localhost:8000,
// override with VITE_PROXY_TARGET env var). The API base used by the client is
// "/api/v1" by default, overridable via VITE_API_BASE_URL (e.g. a full URL when
// the frontend is served from a different origin without the proxy).
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      "/api": {
        target: process.env.VITE_PROXY_TARGET ?? "http://localhost:8000",
        changeOrigin: true,
      },
    },
  },
});
