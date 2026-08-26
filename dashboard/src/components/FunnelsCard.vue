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

// Five funnel layouts, user-selectable and persisted
const LAYOUTS = [
  { key: 'rows', label: 'Rows' },
  { key: 'strip', label: 'Strip' },
  { key: 'taper', label: 'Taper' },
  { key: 'statline', label: 'Statline' },
  { key: 'bars', label: 'Bars' },
] as const
type LayoutKey = (typeof LAYOUTS)[number]['key']
const LAYOUT_KEY = 'melytics_funnel_layout'
const layout = ref<LayoutKey>(
  (() => {
    const v = localStorage.getItem(LAYOUT_KEY)
    return LAYOUTS.some((l) => l.key === v) ? (v as LayoutKey) : 'rows'
  })()
)
const layoutMenu = ref(false)
function setLayout(k: LayoutKey) {
  layout.value = k
  layoutMenu.value = false
  try {
    localStorage.setItem(LAYOUT_KEY, k)
  } catch {}
}

// Share of the first step, step-over-step drop, and the taper ribbon shape
const pct = (f: FunnelRow, s: FunnelStep) => (f.steps[0]?.visitors ? (s.visitors / f.steps[0].visitors) * 100 : 0)
const drop = (f: FunnelRow, i: number) =>
  i && f.steps[i - 1].visitors ? 100 - Math.round((f.steps[i].visitors / f.steps[i - 1].visitors) * 100) : null
function taperPoints(f: FunnelRow): string {
  const n = f.steps.length
  if (n < 2) return ''
  const x = (i: number) => (i / (n - 1)) * 100
  const h = (i: number) => Math.max(pct(f, f.steps[i]) * 0.4, 1.5) // half-height in a 0..40 box
  const top = f.steps.map((_, i) => `${x(i).toFixed(1)},${(20 - h(i) / 2).toFixed(1)}`)
  const bottom = f.steps.map((_, i) => `${x(i).toFixed(1)},${(20 + h(i) / 2).toFixed(1)}`).reverse()
  return [...top, ...bottom].join(' ')
}

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
      <div class="relative ml-auto">
        <button
          class="flex h-7 w-7 items-center justify-center rounded-md text-[var(--ink-3)] hover:bg-[var(--bg)] hover:text-[var(--ink)]"
          title="Funnel layout"
          aria-label="Choose funnel layout"
          @click="layoutMenu = !layoutMenu"
        >
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
            <path d="M4 6h16M4 12h10M4 18h5" />
          </svg>
        </button>
        <div v-if="layoutMenu" class="absolute right-0 top-full z-20 mt-1 w-32 rounded-xl bg-[var(--surface)] py-1 shadow-xl">
          <button
            v-for="l in LAYOUTS"
            :key="l.key"
            class="flex w-full items-center px-3 py-1.5 text-left text-sm hover:bg-[var(--bg)]"
            :class="layout === l.key ? 'text-[var(--accent)] font-medium' : ''"
            @click="setLayout(l.key)"
          >
            {{ l.label }}
          </button>
        </div>
      </div>
      <button
        class="-my-1 flex h-7 w-7 items-center justify-center rounded-md text-[var(--ink-3)] hover:bg-[var(--bg)] hover:text-[var(--ink)]"
        title="Open the setup assistant"
        aria-label="Open the setup assistant"
        @click="emit('assist')"
      >
        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path d="M12 2 C12.9 7.9 16.1 11.1 22 12 C16.1 12.9 12.9 16.1 12 22 C11.1 16.1 7.9 12.9 2 12 C7.9 11.1 11.1 7.9 12 2 Z" />
        </svg>
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
      <!-- Rows: one thin bar per step, drop-off inline -->
      <div v-if="layout === 'rows'" class="space-y-[5px]">
        <div v-for="(s, i) in f.steps" :key="i" class="flex items-center gap-2.5 text-xs tabular-nums">
          <span class="w-20 shrink-0 truncate text-[var(--ink-2)]" :title="s.name">{{ s.name }}</span>
          <div class="h-[18px] flex-1 overflow-hidden rounded-[5px] bg-[var(--bg)]">
            <div class="h-full rounded-[5px] bg-[var(--accent)] opacity-85" :style="{ width: Math.max(pct(f, s), 2) + '%' }" />
          </div>
          <span class="w-9 shrink-0 text-right font-semibold">{{ s.visitors.toLocaleString() }}</span>
          <span class="w-12 shrink-0 text-right text-[11px]" :class="i ? 'text-[var(--down)]' : 'text-[var(--ink-3)]'">
            {{ i ? `−${drop(f, i) ?? 100}%` : '100%' }}
          </span>
        </div>
      </div>

      <!-- Strip: one segmented band, fixed height -->
      <template v-else-if="layout === 'strip'">
        <div class="flex h-[26px] gap-[3px]">
          <div
            v-for="(s, i) in f.steps"
            :key="i"
            class="min-w-2 rounded-md"
            :class="s.visitors ? 'bg-[var(--accent)]' : 'bg-[var(--accent-soft)]'"
            :style="{ flexGrow: Math.max(pct(f, s), 4) }"
          />
        </div>
        <div class="mt-1.5 flex gap-[3px] text-[11px] text-[var(--ink-3)]">
          <span v-for="(s, i) in f.steps" :key="i" class="truncate" :style="{ flexGrow: Math.max(pct(f, s), 4), flexBasis: 0 }" :title="s.name">
            <b class="mr-1 font-semibold text-[var(--ink)] tabular-nums">{{ s.visitors.toLocaleString() }}</b>{{ s.name }}
          </span>
        </div>
      </template>

      <!-- Taper: continuous narrowing ribbon -->
      <template v-else-if="layout === 'taper'">
        <svg viewBox="0 0 100 40" preserveAspectRatio="none" class="block h-14 w-full" aria-hidden="true">
          <polygon :points="taperPoints(f)" fill="var(--accent)" opacity="0.85" />
        </svg>
        <div class="mt-1 flex text-[11px] text-[var(--ink-3)]">
          <div v-for="(s, i) in f.steps" :key="i" class="min-w-0 flex-1" :class="i === 0 ? '' : i === f.steps.length - 1 ? 'text-right' : 'text-center'">
            <b class="block text-[13px] font-semibold text-[var(--ink)] tabular-nums">{{ s.visitors.toLocaleString() }}</b>
            <span class="truncate" :title="s.name">{{ s.name }}</span>
          </div>
        </div>
      </template>

      <!-- Statline: chips joined by arrows -->
      <div v-else-if="layout === 'statline'" class="flex flex-wrap items-center gap-1.5 text-[13px]">
        <template v-for="(s, i) in f.steps" :key="i">
          <span v-if="i" class="text-xs text-[var(--ink-3)]">→</span>
          <span v-if="i" class="text-[11px] tabular-nums text-[var(--down)]">−{{ drop(f, i) ?? 100 }}%</span>
          <span v-if="i" class="text-xs text-[var(--ink-3)]">→</span>
          <span class="flex items-baseline gap-1.5 rounded-lg bg-[var(--bg)] px-2.5 py-1">
            <b class="font-semibold tabular-nums">{{ s.visitors.toLocaleString() }}</b>
            <span class="max-w-28 truncate text-[11px] text-[var(--ink-3)]" :title="s.name">{{ s.name }}</span>
          </span>
        </template>
      </div>

      <!-- Bars: the classic, height-capped and scaled to the first step -->
      <template v-else>
        <div class="flex items-end gap-1 pt-6">
          <div v-for="(s, i) in f.steps" :key="i" class="group/step relative min-w-0 flex-1">
            <div
              v-if="i && f.steps[i - 1].visitors"
              class="absolute -top-5 left-1/2 -translate-x-1/2 whitespace-nowrap text-xs tabular-nums text-[var(--down)] opacity-0 transition-opacity group-hover/step:opacity-100"
            >
              −{{ drop(f, i) }}% drop
            </div>
            <div
              class="mx-auto w-3/4 rounded-t-md bg-[var(--accent)] opacity-85 transition-opacity group-hover/step:opacity-100"
              :style="{ height: `${Math.max(4, pct(f, s) * 0.4)}px` }"
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
      </template>
    </div>
  </section>
</template>
