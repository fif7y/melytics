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

async function load() {
  link.value = await api<ShareLink>(`/sites/${props.siteId}/share`)
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
    <button class="text-sm text-[var(--ink-3)] hover:text-[var(--ink)]" @click="open = !open">Share</button>

    <div v-if="open && link" class="absolute right-0 top-8 z-10 w-80 card p-4 space-y-3">
      <label class="flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          :checked="link.enabled"
          class="accent-[var(--accent)]"
          @change="update({ enabled: ($event.target as HTMLInputElement).checked })"
        />
        Public share link
      </label>

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
