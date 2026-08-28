<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { api, type Site } from '../lib/api'

const props = defineProps<{ site: Site | null }>()
const emit = defineEmits<{
  addSite: [payload: { name: string; domain: string }]
  deleteSite: []
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

// Google sign-in — guided in-app setup (admin pastes the two OAuth values,
// the API persists them; no .env editing, no deploy guide).
const googleOn = ref<boolean | null>(null)
const gSetup = ref(false)
const gClientId = ref('')
const gSecret = ref('')
const gBusy = ref(false)
const gError = ref('')
const gCopied = ref(false)
const redirectUri = `${window.MELYTICS_API ?? location.origin + '/api'}/auth/google/callback`

onMounted(async () => {
  try {
    googleOn.value = (await api<{ google: boolean }>('/auth/config')).google
  } catch {}
})

async function copyRedirect() {
  try {
    await navigator.clipboard.writeText(redirectUri)
    gCopied.value = true
    setTimeout(() => (gCopied.value = false), 1500)
  } catch {}
}

async function saveGoogle() {
  gBusy.value = true
  gError.value = ''
  try {
    await api('/auth/google/settings', {
      method: 'PUT',
      body: JSON.stringify({ client_id: gClientId.value.trim(), client_secret: gSecret.value.trim() }),
    })
    googleOn.value = true
    gSetup.value = false
    gClientId.value = ''
    gSecret.value = ''
  } catch (e) {
    gError.value = e instanceof Error ? e.message : 'Could not save'
  } finally {
    gBusy.value = false
  }
}

async function disableGoogle() {
  gBusy.value = true
  gError.value = ''
  try {
    await api('/auth/google/settings', { method: 'DELETE' })
    googleOn.value = false
  } catch (e) {
    gError.value = e instanceof Error ? e.message : 'Could not save'
  } finally {
    gBusy.value = false
  }
}

// MCP connector — one token per user; regenerating revokes the previous one.
const mcpSetup = ref(false)
const mcpUrl = ref('')
const mcpBusy = ref(false)
const mcpError = ref('')
const mcpCopied = ref(false)

async function generateMcp() {
  mcpBusy.value = true
  mcpError.value = ''
  try {
    const { token } = await api<{ token: string }>('/auth/mcp-token', { method: 'POST' })
    // Sanctum tokens contain "|" — encode so the URL is valid for strict clients
    mcpUrl.value = `${window.MELYTICS_API ?? location.origin + '/api'}/mcp/${encodeURIComponent(token)}`
  } catch (e) {
    mcpError.value = e instanceof Error ? e.message : 'Could not generate'
  } finally {
    mcpBusy.value = false
  }
}

async function copyMcp() {
  try {
    await navigator.clipboard.writeText(mcpUrl.value)
    mcpCopied.value = true
    setTimeout(() => (mcpCopied.value = false), 1500)
  } catch {}
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
    title="Settings"
    aria-label="Settings"
    @click="open = true"
  >
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
      <path d="M21 4h-7M10 4H3M21 12h-9M8 12H3M21 20h-5M12 20H3" />
      <path d="M14 2v4M8 10v4M16 18v4" />
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
        aria-label="Settings"
      >
        <div class="flex items-center px-6 pt-6 pb-5">
          <h2 class="text-sm font-semibold">Settings</h2>
          <button
            class="ml-auto flex h-8 w-8 items-center justify-center rounded-lg text-[var(--ink-3)] hover:bg-[var(--bg)] hover:text-[var(--ink)]"
            title="Close"
            aria-label="Close settings panel"
            @click="open = false"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M18 6 6 18M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div class="flex-1 space-y-8 overflow-y-auto px-6 pb-6">
          <section>
            <div class="mb-2.5 text-xs font-medium uppercase tracking-wide text-[var(--ink-3)]">AI assistants</div>
            <div class="flex items-center justify-between px-2 py-1 text-sm">
              <span>Ask AI about your stats</span>
              <button class="text-xs text-[var(--accent)]" @click="mcpSetup = !mcpSetup">
                {{ mcpSetup ? 'Hide setup' : 'Set up' }}
              </button>
            </div>

            <div v-if="mcpSetup" class="mt-2 space-y-3 rounded-lg bg-[var(--bg)] p-4 text-xs text-[var(--ink-2)]">
              <p>
                Connect Claude — or any MCP-capable assistant — to this dashboard, then just ask:
                <span class="italic">“how was traffic this week?”</span>
              </p>
              <button
                v-if="!mcpUrl"
                class="w-full rounded-md bg-[var(--accent)] py-1.5 font-medium text-white disabled:opacity-50"
                :disabled="mcpBusy"
                @click="generateMcp"
              >Generate connector URL</button>
              <template v-else>
                <div>
                  <button
                    class="w-full rounded-md bg-[var(--surface)] px-2.5 py-2 text-left font-mono text-[10px] break-all hover:ring-1 ring-[var(--accent)]"
                    :title="mcpCopied ? 'Copied' : 'Click to copy'"
                    @click="copyMcp"
                  >{{ mcpUrl }}</button>
                  <p class="mt-0.5" :class="mcpCopied ? 'text-[var(--accent)]' : 'text-[var(--ink-3)]'">{{ mcpCopied ? 'Copied.' : 'Click to copy.' }}</p>
                </div>
                <p>
                  In Claude: <span class="font-medium text-[var(--ink)]">Settings → Connectors → Add custom connector</span>,
                  paste the URL. Done — Claude can now read your stats.
                </p>
                <p class="text-[var(--ink-3)]">
                  The URL contains a secret — treat it like a password. Generating a new one revokes this one.
                </p>
              </template>
              <p v-if="mcpError" class="text-[var(--down)]">{{ mcpError }}</p>
            </div>
          </section>

          <section v-if="googleOn !== null">
            <div class="mb-2.5 text-xs font-medium uppercase tracking-wide text-[var(--ink-3)]">Sign-in</div>
            <div class="flex items-center justify-between px-2 py-1 text-sm">
              <span>Google sign-in</span>
              <button
                v-if="googleOn"
                class="text-xs text-[var(--ink-3)] hover:text-[var(--down)]"
                :disabled="gBusy"
                @click="disableGoogle"
              >On · turn off</button>
              <button
                v-else
                class="text-xs text-[var(--accent)]"
                @click="gSetup = !gSetup"
              >{{ gSetup ? 'Hide setup' : 'Off · set up' }}</button>
            </div>

            <div v-if="gSetup && !googleOn" class="mt-2 space-y-3 rounded-lg bg-[var(--bg)] p-4 text-xs text-[var(--ink-2)]">
              <p>
                <span class="font-medium text-[var(--ink)]">1 ·</span>
                <a
                  class="text-[var(--accent)] underline-offset-2 hover:underline"
                  href="https://console.cloud.google.com/apis/credentials/oauthclient"
                  target="_blank" rel="noopener"
                >Create an OAuth client at Google</a>
                — choose type <span class="font-medium">Web application</span> (free, ~3 minutes).
              </p>
              <div>
                <p><span class="font-medium text-[var(--ink)]">2 ·</span> Under “Authorized redirect URIs”, paste:</p>
                <button
                  class="mt-1.5 w-full rounded-md bg-[var(--surface)] px-2.5 py-2 text-left font-mono text-[10px] break-all hover:ring-1 ring-[var(--accent)]"
                  :title="gCopied ? 'Copied' : 'Click to copy'"
                  @click="copyRedirect"
                >{{ redirectUri }}</button>
                <p class="mt-0.5" :class="gCopied ? 'text-[var(--accent)]' : 'text-[var(--ink-3)]'">{{ gCopied ? 'Copied.' : 'Click to copy.' }}</p>
              </div>
              <div>
                <p class="mb-1.5"><span class="font-medium text-[var(--ink)]">3 ·</span> Paste what Google gives you:</p>
                <input
                  v-model="gClientId"
                  placeholder="Client ID (…apps.googleusercontent.com)"
                  class="mb-2 w-full rounded-md bg-[var(--surface)] px-2.5 py-2 font-mono text-[10px] outline-none focus:ring-1 ring-[var(--accent)] placeholder:font-sans placeholder:text-xs placeholder:text-[var(--ink-3)]"
                />
                <input
                  v-model="gSecret"
                  type="password"
                  placeholder="Client secret"
                  class="w-full rounded-md bg-[var(--surface)] px-2.5 py-2 font-mono text-[10px] outline-none focus:ring-1 ring-[var(--accent)] placeholder:font-sans placeholder:text-xs placeholder:text-[var(--ink-3)]"
                />
              </div>
              <button
                class="w-full rounded-md bg-[var(--accent)] py-1.5 font-medium text-white disabled:opacity-50"
                :disabled="gBusy || !gClientId || !gSecret"
                @click="saveGoogle"
              >Turn on Google sign-in</button>
              <p v-if="gError" class="text-[var(--down)]">{{ gError }}</p>
            </div>
            <p v-else-if="googleOn" class="px-2 text-xs text-[var(--ink-3)]">
              Anyone with an account here can sign in with their Google account of the same email.
            </p>
            <p v-if="gError && !gSetup" class="px-2 text-xs text-[var(--down)]">{{ gError }}</p>
          </section>

          <section v-if="site">
            <div class="mb-2.5 text-xs font-medium uppercase tracking-wide text-[var(--ink-3)]">Tracking snippet</div>
            <p class="mb-2 px-2 text-xs text-[var(--ink-3)]">Paste this on {{ site.domain }}, just before <code class="rounded bg-[var(--bg)] px-1">&lt;/body&gt;</code>:</p>
            <button
              class="w-full rounded-lg bg-[var(--bg)] px-3.5 py-3 text-left font-mono text-[11px] leading-relaxed text-[var(--ink-2)] break-all hover:ring-1 ring-[var(--accent)]"
              :title="copied ? 'Copied' : 'Click to copy'"
              @click="copySnippet"
            >{{ snippet }}</button>
            <p class="mt-1 px-2 text-xs" :class="copied ? 'text-[var(--accent)]' : 'text-[var(--ink-3)]'">
              {{ copied ? 'Copied.' : 'Click to copy.' }}
            </p>
          </section>

          <section>
            <div class="mb-2.5 text-xs font-medium uppercase tracking-wide text-[var(--ink-3)]">Sites</div>
            <div>
              <button
                v-if="!adding"
                class="rounded-lg px-2 py-1.5 text-sm text-[var(--ink-2)] hover:bg-[var(--bg)] hover:text-[var(--ink)]"
                @click="adding = true"
              >
                + Add another site
              </button>
              <form v-else class="space-y-2" @submit.prevent="submitSite">
                <div class="grid gap-2 lg:grid-cols-2">
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
                </div>
                <div class="flex gap-2">
                  <button class="rounded-lg bg-[var(--accent)] px-3 py-1.5 text-sm font-medium text-white">Create</button>
                  <button type="button" class="rounded-lg px-3 py-1.5 text-sm text-[var(--ink-3)]" @click="adding = false">Cancel</button>
                </div>
              </form>
            </div>

            <button
              v-if="site"
              class="mt-1 rounded-lg px-2 py-1.5 text-sm text-[var(--down,#e34948)] hover:bg-[var(--bg)]"
              @click="emit('deleteSite')"
            >
              Delete {{ site.domain }}…
            </button>
          </section>
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
