<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { type Me, type Site } from '../lib/api'
import Toggle from './Toggle.vue'
import { theme, setTheme, type Theme, accent, accentHex, setAccent, setAccentHex, applyTheme, ACCENTS } from '../lib/theme'

// Live-preview a custom color while the native picker is open — commit on close
// (setAccentHex on @change) so dragging doesn't spam storage or chart rebuilds.
function previewHex(hex: string) {
  document.documentElement.style.setProperty('--accent', hex)
  document.documentElement.style.setProperty('--accent-soft', `color-mix(in srgb, ${hex} 14%, transparent)`)
}

const THEMES: { key: Theme; label: string }[] = [
  { key: 'system', label: 'System' },
  { key: 'light', label: 'Light' },
  { key: 'dark', label: 'Dark' },
]

const props = defineProps<{ site: Site | null; me: Me | null; density: 'comfy' | 'compact' }>()
const emit = defineEmits<{
  notify: [field: 'digest_enabled' | 'alerts_enabled', on: boolean]
  density: [d: 'comfy' | 'compact']
  signout: []
}>()

const open = ref(false)

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') open.value = false
}
onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <button
    class="flex h-9 w-9 items-center justify-center rounded-lg text-[var(--ink-2)] hover:bg-[var(--surface)] hover:text-[var(--ink)]"
    title="Account"
    aria-label="Account"
    @click="open = true"
  >
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="8" r="4" />
      <path d="M4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5" />
    </svg>
  </button>

  <Teleport to="body">
    <Transition name="scrim">
      <div v-if="open" class="fixed inset-0 z-30 bg-black/25" @click="open = false" />
    </Transition>
    <Transition name="drawer">
      <aside
        v-if="open"
        class="fixed right-0 top-0 z-40 flex h-full w-80 max-w-[85vw] flex-col bg-[var(--surface)] shadow-2xl lg:w-[30rem]"
        role="dialog"
        aria-label="Account"
      >
        <div class="flex items-center px-6 pt-6 pb-5">
          <div>
            <h2 class="text-sm font-semibold">Account</h2>
            <p v-if="me" class="text-xs text-[var(--ink-3)]">{{ me.email }}</p>
          </div>
          <button
            class="ml-auto flex h-8 w-8 items-center justify-center rounded-lg text-[var(--ink-3)] hover:bg-[var(--bg)] hover:text-[var(--ink)]"
            title="Close"
            aria-label="Close account panel"
            @click="open = false"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M18 6 6 18M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div class="flex-1 space-y-8 overflow-y-auto px-6 pb-6">
          <div class="grid gap-6 lg:grid-cols-2">
            <section>
              <div class="mb-2.5 text-xs font-medium uppercase tracking-wide text-[var(--ink-3)]">Appearance</div>
              <div class="flex gap-1 rounded-lg bg-[var(--bg)] p-1">
                <button
                  v-for="t in THEMES"
                  :key="t.key"
                  class="flex-1 rounded-md px-2 py-1.5 text-sm"
                  :class="theme === t.key ? 'bg-[var(--accent-soft)] font-medium text-[var(--accent)]' : 'text-[var(--ink-2)]'"
                  @click="setTheme(t.key)"
                >
                  {{ t.label }}
                </button>
              </div>
            </section>

            <section>
              <div class="mb-2.5 text-xs font-medium uppercase tracking-wide text-[var(--ink-3)]">Density</div>
              <div class="flex gap-1 rounded-lg bg-[var(--bg)] p-1">
                <button
                  v-for="d in (['comfy', 'compact'] as const)"
                  :key="d"
                  class="flex-1 rounded-md px-2 py-1.5 text-sm"
                  :class="props.density === d ? 'bg-[var(--accent-soft)] font-medium text-[var(--accent)]' : 'text-[var(--ink-2)]'"
                  @click="emit('density', d)"
                >
                  {{ d === 'comfy' ? 'Comfortable' : 'Compact' }}
                </button>
              </div>
            </section>
          </div>

          <section>
            <div class="mb-3 text-xs font-medium uppercase tracking-wide text-[var(--ink-3)]">Highlight color</div>
            <div class="flex items-center gap-4 px-1">
              <button
                v-for="a in ACCENTS"
                :key="a"
                class="h-5 w-5 rounded-full transition-[box-shadow,transform] duration-150 ease-out active:scale-90"
                :style="{ background: `var(--accent-${a})` }"
                :class="accent === a ? 'ring-2 ring-[var(--ink)] ring-offset-2 ring-offset-[var(--surface)]' : 'hover:ring-2 hover:ring-[var(--ink-3)] hover:ring-offset-2 hover:ring-offset-[var(--surface)]'"
                :title="a.charAt(0).toUpperCase() + a.slice(1)"
                :aria-label="`${a} accent`"
                :aria-pressed="accent === a"
                @click="setAccent(a)"
              />
              <label
                class="relative h-5 w-5 cursor-pointer rounded-full transition-[box-shadow,transform] duration-150 ease-out active:scale-90"
                :style="{ background: accentHex ?? 'conic-gradient(from 0deg, #2a78d6, #7c3aed, #be185d, #c2410c, #b45309, #15803d, #0f766e, #2a78d6)' }"
                :class="accent === 'custom' ? 'ring-2 ring-[var(--ink)] ring-offset-2 ring-offset-[var(--surface)]' : 'hover:ring-2 hover:ring-[var(--ink-3)] hover:ring-offset-2 hover:ring-offset-[var(--surface)]'"
                title="Custom"
              >
                <input
                  type="color"
                  class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                  aria-label="Custom accent color"
                  :value="accentHex ?? '#2a78d6'"
                  @input="previewHex(($event.target as HTMLInputElement).value)"
                  @change="setAccentHex(($event.target as HTMLInputElement).value)"
                  @blur="applyTheme()"
                />
              </label>
            </div>
          </section>

          <section v-if="site">
            <div class="mb-2.5 text-xs font-medium uppercase tracking-wide text-[var(--ink-3)]">Notifications</div>
            <Toggle label="Weekly digest email" :on="site.digest_enabled" @change="(on) => emit('notify', 'digest_enabled', on)" />
            <Toggle label="Spike & drop alerts" :on="site.alerts_enabled" @change="(on) => emit('notify', 'alerts_enabled', on)" />
            <p class="mt-1.5 px-2 text-xs text-[var(--ink-3)]">Alerts compare today to the trailing week, at most once a day.</p>
            <p v-if="me?.mail_off" class="mt-1.5 px-2 text-xs text-[var(--warn)]">
              Email sending isn't configured, so these won't be delivered — set it up
              under Settings → Email delivery.
            </p>
          </section>
        </div>

        <div class="px-6 py-5">
          <button
            class="flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-sm text-[var(--ink-2)] hover:bg-[var(--bg)] hover:text-[var(--ink)]"
            @click="emit('signout')"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
              <path d="m16 17 5-5-5-5M21 12H9" />
            </svg>
            Sign out
          </button>
          <p v-if="me?.version" class="mt-2 px-2 text-xs text-[var(--ink-3)]">
            melytics {{ me.version === 'dev' ? 'dev' : `v${me.version}` }}
            <template v-if="me.update"> · <span class="text-[var(--accent)]">v{{ me.update.latest }} available</span></template>
          </p>
        </div>
      </aside>
    </Transition>
  </Teleport>
</template>

<style scoped>
.drawer-enter-active,
.drawer-leave-active {
  transition: transform 0.22s cubic-bezier(0.22, 1, 0.36, 1);
}
.drawer-enter-from,
.drawer-leave-to {
  transform: translateX(100%);
}
.scrim-enter-active,
.scrim-leave-active {
  transition: opacity 0.22s ease;
}
.scrim-enter-from,
.scrim-leave-to {
  opacity: 0;
}
@media (prefers-reduced-motion: reduce) {
  .drawer-enter-active,
  .drawer-leave-active,
  .scrim-enter-active,
  .scrim-leave-active {
    transition: none;
  }
}
</style>
