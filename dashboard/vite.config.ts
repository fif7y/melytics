import { defineConfig, type Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

// The built SPA is fully self-contained (no external scripts/fonts/APIs), so it
// ships under a tight CSP. 'unsafe-inline' style covers Vue's runtime :style
// bindings and the chart tooltip; frame-ancestors is a header (X-Frame-Options),
// which <meta> can't express. Build-only — the dev server needs inline/eval for HMR.
const CSP =
  "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; base-uri 'self'; form-action 'self'; object-src 'none'"

const injectCsp = (): Plugin => ({
  name: 'inject-csp',
  apply: 'build',
  transformIndexHtml: () => [
    { tag: 'meta', attrs: { 'http-equiv': 'Content-Security-Policy', content: CSP }, injectTo: 'head' },
  ],
})

// base './' + hash router = mountable anywhere (stats.fif7y.com or fif7y.com/melytics)
export default defineConfig({
  base: './',
  plugins: [vue(), tailwindcss(), injectCsp()],
  server: {
    port: Number(process.env.PORT) || 5173, // harness assigns PORT when 5173 is taken
    proxy: { '/api': 'http://127.0.0.1:8901' },
  },
})
