<script setup lang="ts">
import { ref } from 'vue'
import { api } from '../lib/api'
import TargetPicker from './TargetPicker.vue'

export interface FunnelStep {
  name: string
  visitors: number
  rate: number
}
export interface FunnelRow {
  id: number
  name: string
  definition?: { name?: string; event?: string | null; path_pattern?: string | null }[]
  steps: FunnelStep[]
}

const props = defineProps<{ siteId: number; funnels: FunnelRow[]; targets?: { pages: string[]; events: string[] } }>()
const emit = defineEmits<{ changed: []; assist: [] }>()

// One wizard-style builder serves both add and edit
const formOpen = ref(false)
const editingId = ref<number | null>(null)
const fName = ref('')
const fSteps = ref<string[]>(['', ''])
const busy = ref(false)

function openAdd() {
  formOpen.value = !formOpen.value
  editingId.value = null
  fName.value = ''
  fSteps.value = ['', '']
}

function startEdit(f: FunnelRow) {
  formOpen.value = true
  editingId.value = f.id
  fName.value = f.name
  fSteps.value = (f.definition ?? []).map((s) => s.event ?? s.path_pattern ?? '')
  if (fSteps.value.length < 2) fSteps.value = ['', '']
}

async function save() {
  const steps = fSteps.value
    .map((s) => s.trim())
    .filter(Boolean)
    .map((s) => (s.startsWith('/') ? { name: s, path_pattern: s } : { name: s, event: s }))
  if (!fName.value || steps.length < 2) return
  busy.value = true
  try {
    await api(editingId.value ? `/sites/${props.siteId}/funnels/${editingId.value}` : `/sites/${props.siteId}/funnels`, {
      method: editingId.value ? 'PATCH' : 'POST',
      body: JSON.stringify({ name: fName.value, steps }),
    })
    formOpen.value = false
    editingId.value = null
    emit('changed')
  } catch (e) {
    alert(e instanceof Error ? e.message : 'Could not save the funnel')
  } finally {
    busy.value = false
  }
}

async function remove(id: number) {
  try {
    await api(`/sites/${props.siteId}/funnels/${id}`, { method: 'DELETE' })
    emit('changed')
  } catch (e) {
    alert(e instanceof Error ? e.message : 'Could not delete the funnel')
  }
}
</script>

<template>
  <section class="card p-5">
    <div class="flex items-center gap-3 mb-4">
      <h3 class="text-sm font-medium text-[var(--ink-2)]">Funnels</h3>
      <button class="ml-auto text-sm text-[var(--ink-3)] hover:text-[var(--ink)]" title="Open the setup assistant" @click="emit('assist')">
        Assistant
      </button>
      <button class="text-sm text-[var(--accent)]" @click="openAdd">
        {{ formOpen ? 'Cancel' : 'Add funnel' }}
      </button>
    </div>

    <form v-if="formOpen" class="mb-5 space-y-2" @submit.prevent="save">
      <input
        v-model="fName"
        placeholder="Name"
        class="w-full rounded-lg px-3 py-1.5 text-sm font-medium bg-[var(--bg)] outline-none focus:ring-2 ring-[var(--accent)] placeholder:text-[var(--ink-3)]"
      />
      <div v-for="(_, i) in fSteps" :key="i" class="flex items-center gap-2">
        <span class="w-4 text-right text-xs tabular-nums text-[var(--ink-3)]">{{ i + 1 }}</span>
        <TargetPicker
          v-model="fSteps[i]"
          :targets="targets"
          placeholder="Pick a page or event, or type your own"
        />
        <button
          v-if="fSteps.length > 2"
          type="button"
          class="text-[var(--ink-3)] hover:text-[var(--down)]"
          :aria-label="`Remove step ${i + 1}`"
          @click="fSteps.splice(i, 1)"
        >
          ×
        </button>
      </div>
      <div class="flex items-center gap-2 pl-6">
        <button v-if="fSteps.length < 8" type="button" class="text-sm text-[var(--accent)]" @click="fSteps.push('')">+ Add step</button>
        <button :disabled="busy" class="ml-auto rounded-lg px-3 py-1.5 text-sm text-white bg-[var(--accent)] disabled:opacity-50">
          {{ editingId ? 'Save changes' : 'Create funnel' }}
        </button>
      </div>
    </form>

    <p v-if="!funnels.length && !formOpen" class="text-sm text-[var(--ink-3)]">
      See where visitors drop off across a sequence of pages or events.
    </p>

    <div v-for="f in funnels" :key="f.id" class="group mb-5 last:mb-0">
      <div class="flex items-center mb-2">
        <span class="text-sm font-medium">{{ f.name }}</span>
        <span v-if="f.steps.length" class="ml-2 text-xs text-[var(--ink-3)]">
          {{ f.steps[f.steps.length - 1].rate }}% overall
        </span>
        <button
          class="ml-auto -my-1 flex h-7 w-7 items-center justify-center rounded-md text-[var(--ink-3)] opacity-60 transition-opacity hover:bg-[var(--bg)] hover:text-[var(--ink)] group-hover:opacity-100"
          title="Edit funnel"
          aria-label="Edit funnel"
          @click="startEdit(f)"
        >
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z" />
          </svg>
        </button>
        <button
          class="-my-1 flex h-7 w-7 items-center justify-center rounded-md text-[var(--ink-3)] opacity-60 transition-opacity hover:bg-[var(--bg)] hover:text-[var(--down)] group-hover:opacity-100"
          title="Delete funnel"
          aria-label="Delete funnel"
          @click="remove(f.id)"
        >
          ×
        </button>
      </div>
      <div class="flex items-end gap-1 pt-6">
        <div v-for="(s, i) in f.steps" :key="i" class="group/step relative min-w-0 flex-1">
          <div
            v-if="i && f.steps[i - 1].visitors"
            class="absolute -top-5 left-1/2 -translate-x-1/2 whitespace-nowrap text-xs tabular-nums text-[var(--down)] opacity-0 transition-opacity group-hover/step:opacity-100"
          >
            −{{ 100 - Math.round((s.visitors / f.steps[i - 1].visitors) * 100) }}% drop
          </div>
          <div
            class="mx-auto w-3/4 rounded-t-md bg-[var(--accent)] opacity-85 transition-opacity group-hover/step:opacity-100"
            :style="{ height: `${Math.max(6, s.rate * 1.1)}px` }"
          />
        </div>
      </div>
      <div class="h-px bg-[color-mix(in_srgb,var(--ink)_12%,transparent)]" />
      <div class="mt-1.5 flex gap-1">
        <div v-for="(s, i) in f.steps" :key="i" class="min-w-0 flex-1 text-center">
          <div class="text-sm font-semibold tabular-nums">{{ s.visitors.toLocaleString() }}</div>
          <div class="truncate text-xs text-[var(--ink-3)]" :title="s.name">{{ s.name }}</div>
        </div>
      </div>
    </div>
  </section>
</template>
