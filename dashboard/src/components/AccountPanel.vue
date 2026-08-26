<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import type { Me, Site } from '../lib/api'

const props = defineProps<{ site: Site | null; me: Me | null }>()
const emit = defineEmits<{
  notify: [field: 'digest_enabled' | 'alerts_enabled', on: boolean]
  addSite: [payload: { name: string; domain: string }]
  deleteSite: []
  signout: []
}>()

const open = ref(false)

const snippet = computed(() =>
  props.site
    ? `<script defer data-site="${props.site.key}" data-api="${location.origin}/api/echo" src="${location.origin}/m.js"><\/script>`
    : ''
)
const copied = ref(false)
async function copySnippet() {
  try {
    await navigator.clipboard.writeText(snippet.value)
    copied.value = true
    setTimeout(() => (copied.value = false), 1500)
  } catch {}
}

const adding = ref(false)
const newName = ref('')
const newDomain = ref('')
function submitSite() {
  if (!newName.value || !newDomain.value) return
  emit('addSite', { name: newName.value, domain: newDomain.value })
  adding.value = false
  newName.value = ''
  newDomain.value = ''
}

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
        class="fixed right-0 top-0 z-40 flex h-full w-80 max-w-[85vw] flex-col bg-[var(--surface)] shadow-2xl"
        role="dialog"
        aria-label="Account"
      >
        <div class="flex items-center px-5 pt-5 pb-4">
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

        <div class="flex-1 space-y-6 overflow-y-auto px-5 pb-5">
          <section v-if="site">
            <div class="mb-2 text-xs font-medium uppercase tracking-wide text-[var(--ink-3)]">Notifications</div>
            <label class="flex cursor-pointer items-center gap-3 rounded-lg px-2 py-2 text-sm hover:bg-[var(--bg)]">
              <input
                type="checkbox"
                class="accent-[var(--accent)]"
                :checked="site.digest_enabled"
                @change="emit('notify', 'digest_enabled', ($event.target as HTMLInputElement).checked)"
              />
              Weekly digest email
            </label>
            <label class="flex cursor-pointer items-center gap-3 rounded-lg px-2 py-2 text-sm hover:bg-[var(--bg)]">
              <input
                type="checkbox"
                class="accent-[var(--accent)]"
                :checked="site.alerts_enabled"
                @change="emit('notify', 'alerts_enabled', ($event.target as HTMLInputElement).checked)"
              />
              Spike &amp; drop alerts
            </label>
            <p class="mt-1 px-2 text-xs text-[var(--ink-3)]">Alerts compare today to the trailing week, at most once a day.</p>
          </section>

          <section>
            <div class="mb-2 text-xs font-medium uppercase tracking-wide text-[var(--ink-3)]">Site</div>
            <template v-if="site">
              <p class="mb-1.5 px-2 text-xs text-[var(--ink-3)]">Paste this on {{ site.domain }}, just before <code class="rounded bg-[var(--bg)] px-1">&lt;/body&gt;</code>:</p>
              <button
                class="w-full rounded-lg bg-[var(--bg)] px-3 py-2.5 text-left font-mono text-[11px] leading-relaxed text-[var(--ink-2)] break-all hover:ring-1 ring-[var(--accent)]"
                :title="copied ? 'Copied' : 'Click to copy'"
                @click="copySnippet"
              >{{ snippet }}</button>
              <p class="mt-1 px-2 text-xs" :class="copied ? 'text-[var(--accent)]' : 'text-[var(--ink-3)]'">
                {{ copied ? 'Copied.' : 'Click to copy.' }}
              </p>
            </template>

            <div class="mt-4">
              <button
                v-if="!adding"
                class="rounded-lg px-2 py-1.5 text-sm text-[var(--ink-2)] hover:bg-[var(--bg)] hover:text-[var(--ink)]"
                @click="adding = true"
              >
                + Add another site
              </button>
              <form v-else class="space-y-2" @submit.prevent="submitSite">
                <input
                  v-model="newName"
                  placeholder="Name (e.g. My blog)"
                  required
                  class="w-full rounded-lg bg-[var(--bg)] px-3 py-2 text-sm outline-none focus:ring-2 ring-[var(--accent)] placeholder:text-[var(--ink-3)]"
                />
                <input
                  v-model="newDomain"
                  placeholder="Domain (e.g. blog.example.com)"
                  required
                  class="w-full rounded-lg bg-[var(--bg)] px-3 py-2 text-sm outline-none focus:ring-2 ring-[var(--accent)] placeholder:text-[var(--ink-3)]"
                />
                <div class="flex gap-2">
                  <button class="rounded-lg bg-[var(--accent)] px-3 py-1.5 text-sm font-medium text-white">Create</button>
                  <button type="button" class="rounded-lg px-3 py-1.5 text-sm text-[var(--ink-3)]" @click="adding = false">Cancel</button>
                </div>
              </form>
            </div>

            <button
              v-if="site"
              class="mt-4 rounded-lg px-2 py-1.5 text-sm text-[var(--down,#e34948)] hover:bg-[var(--bg)]"
              @click="emit('deleteSite')"
            >
              Delete this site…
            </button>
          </section>
        </div>

        <div class="px-5 py-4">
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
