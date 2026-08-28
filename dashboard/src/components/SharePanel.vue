<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '../lib/api'

interface ShareLink {
  token: string
  enabled: boolean
  has_password: boolean
}

const props = defineProps<{ siteId: number }>()

const open = ref(false)
const link = ref<ShareLink | null>(null)
const password = ref('')
const copied = ref(false)

const shareUrl = () => `${location.origin}${location.pathname}#/share/${link.value?.token}`

// No on/off checkbox anymore — opening the panel is the intent, so a disabled
// link is enabled on load and the panel just shows the URL + password.
async function load() {
  link.value = await api<ShareLink>(`/sites/${props.siteId}/share`)
  if (!link.value.enabled) await update({ enabled: true })
}

async function update(patch: Record<string, unknown>) {
  link.value = await api<ShareLink>(`/sites/${props.siteId}/share`, {
    method: 'PATCH',
    body: JSON.stringify(patch),
  })
}

async function copy() {
  try {
    await navigator.clipboard.writeText(shareUrl())
    copied.value = true
    setTimeout(() => (copied.value = false), 1500)
  } catch {}
}

onMounted(load)
</script>

<template>
  <div class="relative">
    <button
      class="flex h-9 w-9 items-center justify-center rounded-lg text-[var(--ink-2)] hover:bg-[var(--surface)] hover:text-[var(--ink)]"
      :class="{ 'bg-[var(--surface)] text-[var(--ink)]': open }"
      title="Share"
      aria-label="Share"
      @click="open = !open"
    >
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="18" cy="5" r="3" />
        <circle cx="6" cy="12" r="3" />
        <circle cx="18" cy="19" r="3" />
        <path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4" />
      </svg>
    </button>

    <div v-if="open && link" class="absolute right-0 top-11 z-10 w-80 card p-4 space-y-3 shadow-lg">
      <h2 class="text-sm font-semibold">Public share link</h2>

      <template v-if="link.enabled">
        <div class="flex items-center gap-2">
          <input
            readonly
            :value="shareUrl()"
            class="flex-1 truncate rounded-lg px-2.5 py-1.5 text-xs bg-[var(--bg)] text-[var(--ink-2)] outline-none"
            @focus="($event.target as HTMLInputElement).select()"
          />
          <button class="text-sm text-[var(--accent)] shrink-0" @click="copy">
            {{ copied ? 'Copied' : 'Copy' }}
          </button>
        </div>

        <form class="flex items-center gap-2" @submit.prevent="update({ password }).then(() => (password = ''))">
          <input
            v-model="password"
            type="password"
            :placeholder="link.has_password ? 'Change password' : 'Add password (optional)'"
            class="flex-1 rounded-lg px-2.5 py-1.5 text-sm bg-[var(--bg)] outline-none focus:ring-2 ring-[var(--accent)] placeholder:text-[var(--ink-3)]"
          />
          <button class="text-sm text-[var(--accent)]" :disabled="password.length < 6">Set</button>
        </form>
        <button
          v-if="link.has_password"
          class="text-xs text-[var(--ink-3)] hover:text-[var(--ink)]"
          @click="update({ password: null })"
        >
          Remove password
        </button>
      </template>
    </div>
  </div>
</template>
