<script setup lang="ts">
import { ref } from 'vue'
import { api } from '../lib/api'

export interface GoalRow {
  id: number
  name: string
  event: string | null
  path_pattern: string | null
  conversions: number
  rate: number
}

const props = defineProps<{ siteId: number; goals: GoalRow[] }>()
const emit = defineEmits<{ changed: []; assist: [] }>()

const adding = ref(false)
const name = ref('')
const target = ref('')
const busy = ref(false)

async function add() {
  if (!name.value || !target.value) return
  busy.value = true
  const isPath = target.value.startsWith('/')
  try {
    await api(`/sites/${props.siteId}/goals`, {
      method: 'POST',
      body: JSON.stringify({
        name: name.value,
        [isPath ? 'path_pattern' : 'event']: target.value,
      }),
    })
    name.value = ''
    target.value = ''
    adding.value = false
    emit('changed')
  } finally {
    busy.value = false
  }
}

async function remove(id: number) {
  await api(`/sites/${props.siteId}/goals/${id}`, { method: 'DELETE' })
  emit('changed')
}
</script>

<template>
  <section class="card p-5">
    <div class="flex items-center mb-4">
      <h3 class="text-sm font-medium text-[var(--ink-2)]">Goals</h3>
      <button
        class="ml-auto text-sm text-[var(--accent)]"
        @click="adding = !adding"
      >
        {{ adding ? 'Cancel' : 'Add goal' }}
      </button>
    </div>

    <form v-if="adding" class="flex gap-2 mb-4" @submit.prevent="add">
      <input
        v-model="name"
        placeholder="Name"
        class="w-28 rounded-lg px-3 py-1.5 text-sm bg-[var(--bg)] outline-none focus:ring-2 ring-[var(--accent)] placeholder:text-[var(--ink-3)]"
      />
      <input
        v-model="target"
        placeholder="event name, or /path (wildcards ok)"
        class="flex-1 rounded-lg px-3 py-1.5 text-sm bg-[var(--bg)] outline-none focus:ring-2 ring-[var(--accent)] placeholder:text-[var(--ink-3)]"
      />
      <button :disabled="busy" class="rounded-lg px-3 py-1.5 text-sm text-white bg-[var(--accent)] disabled:opacity-50">Save</button>
    </form>

    <p v-if="!goals.length && !adding" class="text-sm text-[var(--ink-3)]">
      Track conversions: an event (<code>melytics.track('signup')</code>) or a page visit (<code>/thanks</code>).
      New to goals? <button class="text-[var(--accent)]" @click="emit('assist')">Open the setup assistant</button>.
    </p>

    <ul class="space-y-1.5">
      <li v-for="g in goals" :key="g.id" class="group flex items-baseline gap-3 rounded-md px-2.5 py-1.5">
        <span class="text-sm">{{ g.name }}</span>
        <span class="text-xs text-[var(--ink-3)]">{{ g.event ?? g.path_pattern }}</span>
        <span class="ml-auto text-sm tabular-nums">{{ g.conversions.toLocaleString() }}</span>
        <span class="text-sm tabular-nums text-[var(--ink-2)] w-14 text-right">{{ g.rate }}%</span>
        <button
          class="opacity-0 group-hover:opacity-100 text-[var(--ink-3)] hover:text-[var(--down)] text-sm"
          title="Delete goal"
          @click="remove(g.id)"
        >
          ×
        </button>
      </li>
    </ul>
  </section>
</template>
