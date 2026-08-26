<script setup lang="ts">
import { ref } from 'vue'
import { api } from '../lib/api'

export interface FunnelStep {
  name: string
  visitors: number
  rate: number
}
export interface FunnelRow {
  id: number
  name: string
  steps: FunnelStep[]
}

const props = defineProps<{ siteId: number; funnels: FunnelRow[] }>()
const emit = defineEmits<{ changed: [] }>()

const adding = ref(false)
const name = ref('')
const stepsText = ref('')
const busy = ref(false)

async function add() {
  const steps = stepsText.value
    .split('\n')
    .map((l) => l.trim())
    .filter(Boolean)
    .map((l) => {
      // "Label = target" or just the target; leading / means path
      const [target, label] = l.includes('=') ? [l.split('=')[1].trim(), l.split('=')[0].trim()] : [l, l]
      return target.startsWith('/')
        ? { name: label, path_pattern: target }
        : { name: label, event: target }
    })
  if (!name.value || steps.length < 2) return
  busy.value = true
  try {
    await api(`/sites/${props.siteId}/funnels`, {
      method: 'POST',
      body: JSON.stringify({ name: name.value, steps }),
    })
    name.value = ''
    stepsText.value = ''
    adding.value = false
    emit('changed')
  } finally {
    busy.value = false
  }
}

async function remove(id: number) {
  await api(`/sites/${props.siteId}/funnels/${id}`, { method: 'DELETE' })
  emit('changed')
}
</script>

<template>
  <section class="card p-5">
    <div class="flex items-center mb-4">
      <h3 class="text-sm font-medium text-[var(--ink-2)]">Funnels</h3>
      <button class="ml-auto text-sm text-[var(--accent)]" @click="adding = !adding">
        {{ adding ? 'Cancel' : 'Add funnel' }}
      </button>
    </div>

    <form v-if="adding" class="space-y-2 mb-4" @submit.prevent="add">
      <input
        v-model="name"
        placeholder="Name"
        class="w-full rounded-lg px-3 py-1.5 text-sm bg-[var(--bg)] outline-none focus:ring-2 ring-[var(--accent)] placeholder:text-[var(--ink-3)]"
      />
      <textarea
        v-model="stepsText"
        rows="3"
        placeholder="One step per line, in order: event name or /path (wildcards ok).&#10;Optional label: Landing = /pricing"
        class="w-full rounded-lg px-3 py-1.5 text-sm bg-[var(--bg)] outline-none focus:ring-2 ring-[var(--accent)] placeholder:text-[var(--ink-3)]"
      />
      <button :disabled="busy" class="rounded-lg px-3 py-1.5 text-sm text-white bg-[var(--accent)] disabled:opacity-50">Save</button>
    </form>

    <p v-if="!funnels.length && !adding" class="text-sm text-[var(--ink-3)]">
      See where visitors drop off across a sequence of pages or events.
    </p>

    <div v-for="f in funnels" :key="f.id" class="group mb-5 last:mb-0">
      <div class="flex items-center mb-2">
        <span class="text-sm font-medium">{{ f.name }}</span>
        <span v-if="f.steps.length" class="ml-2 text-xs text-[var(--ink-3)]">
          {{ f.steps[f.steps.length - 1].rate }}% overall
        </span>
        <button
          class="ml-auto opacity-0 group-hover:opacity-100 text-[var(--ink-3)] hover:text-[var(--down)] text-sm"
          title="Delete funnel"
          @click="remove(f.id)"
        >
          ×
        </button>
      </div>
      <div class="space-y-1.5">
        <div v-for="(s, i) in f.steps" :key="i" class="flex items-center gap-3">
          <span class="w-32 truncate text-sm text-[var(--ink-2)]" :title="s.name">{{ s.name }}</span>
          <div class="relative h-6 flex-1 rounded-md bg-[var(--bg)] overflow-hidden">
            <div
              class="absolute inset-y-0 left-0 rounded-md bg-[var(--accent-soft)]"
              :style="{ width: `${Math.max(s.rate, 1)}%` }"
            />
          </div>
          <span class="w-16 text-right text-sm tabular-nums">{{ s.visitors.toLocaleString() }}</span>
          <span class="w-14 text-right text-sm tabular-nums text-[var(--ink-2)]">{{ s.rate }}%</span>
        </div>
      </div>
    </div>
  </section>
</template>
