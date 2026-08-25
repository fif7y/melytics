import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

// base './' + hash router = mountable anywhere (stats.fif7y.com or fif7y.com/melytics)
export default defineConfig({
  base: './',
  plugins: [vue(), tailwindcss()],
  server: {
    proxy: { '/api': 'http://127.0.0.1:8901' },
  },
})
