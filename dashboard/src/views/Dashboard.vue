<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, setToken, type Annotation, type BreakdownRow, type Site, type Stats } from '../lib/api'
import TimeChart from '../components/TimeChart.vue'
import BreakdownCard from '../components/BreakdownCard.vue'
import StatStrip from '../components/StatStrip.vue'
import GoalsCard, { type GoalRow } from '../components/GoalsCard.vue'
import FunnelsCard, { type FunnelRow } from '../components/FunnelsCard.vue'
import VitalsCard, { type Vitals } from '../components/VitalsCard.vue'
import SharePanel from '../components/SharePanel.vue'
import SettingsPanel from '../components/SettingsPanel.vue'

const route = useRoute()
const router = useRouter()

const sites = ref<Site[]>([])
const stats = ref<Stats | null>(null)
const goals = ref<GoalRow[]>([])
const funnels = ref<FunnelRow[]>([])
const vitals = ref<Vitals | null>(null)
const annotations = ref<Annotation[]>([])
const noting = ref(false)
const noteDay = ref(new Date().toISOString().slice(0, 10))
const noteText = ref('')
const breakdowns = ref<Record<string, BreakdownRow[]>>({})
const live = ref<number | null>(null)
const metric = ref<'visitors' | 'pageviews'>('visitors')
const rangeDays = ref(30)
const filter = ref<{ dim: string; value: string } | null>(null)
const loading = ref(true)

const RANGES = [
  { label: '7d', days: 7 },
  { label: '30d', days: 30 },
  { label: '90d', days: 90 },
]
const PANELS = [
  { key: 'page', title: 'Pages' },
  { key: 'referrer', title: 'Referrers' },
  { key: 'country', title: 'Countries' },
  { key: 'device', title: 'Devices' },
  { key: 'browser', title: 'Browsers' },
  { key: 'utm_campaign', title: 'Campaigns' },
  { key: 'event', title: 'Events' },
]

const MODULES = [
  { key: 'vitals', label: 'Web Vitals' },
  { key: 'goals', label: 'Goals' },
  { key: 'funnels', label: 'Funnels' },
  ...PANELS.map((p) => ({ key: p.key, label: p.title })),
]

const hidden = ref<string[]>(
  (() => {
    try {
      return JSON.parse(localStorage.getItem('melytics_hidden') ?? '[]')
    } catch {
      return []
    }
  })()
)
const show = (key: string) => !hidden.value.includes(key)

// Drag-to-reorder breakdown cards, persisted like the hide-toggles
const ORDER_KEY = 'melytics_order'
const order = ref<string[]>(
  (() => {
    try {
      return JSON.parse(localStorage.getItem(ORDER_KEY) ?? '[]')
    } catch {
      return []
    }
  })()
)
// Vitals lives in the same reorderable grid as the breakdowns
const GRID_ITEMS = [{ key: 'vitals', title: 'Web Vitals' }, ...PANELS]
const orderedPanels = computed(() => {
  const idx = (k: string) => {
    const i = order.value.indexOf(k)
    return i === -1 ? 100 + GRID_ITEMS.findIndex((p) => p.key === k) : i
  }
  return GRID_ITEMS.filter((p) => show(p.key)).slice().sort((a, b) => idx(a.key) - idx(b.key))
})
const dragKey = ref<string | null>(null)
const overKey = ref<string | null>(null)
function dropOn(target: string) {
  if (!dragKey.value || dragKey.value === target) return
  const keys = orderedPanels.value.map((p) => p.key)
  keys.splice(keys.indexOf(target), 0, keys.splice(keys.indexOf(dragKey.value), 1)[0])
  order.value = keys
  try {
    localStorage.setItem(ORDER_KEY, JSON.stringify(keys))
  } catch {}
}

// Density: compact tightens card padding and row spacing
const DENSITY_KEY = 'melytics_density'
const density = ref<'comfy' | 'compact'>(localStorage.getItem(DENSITY_KEY) === 'compact' ? 'compact' : 'comfy')
function setDensity(d: 'comfy' | 'compact') {
  density.value = d
  try {
    localStorage.setItem(DENSITY_KEY, d)
  } catch {}
}

function toggleModule(key: string) {
  hidden.value = show(key) ? [...hidden.value, key] : hidden.value.filter((k) => k !== key)
  try {
    localStorage.setItem('melytics_hidden', JSON.stringify(hidden.value))
  } catch {}
  load()
}

const siteId = computed(() => Number(route.params.siteId) || sites.value[0]?.id)
const site = computed(() => sites.value.find((s) => s.id === siteId.value))

const delta = computed(() => {
  if (!stats.value) return null
  const cur = stats.value.totals[metric.value]
  const prev = stats.value.previous_totals[metric.value]
  if (!prev) return null
  return Math.round(((cur - prev) / prev) * 100)
})

function rangeParams() {
  const to = new Date()
  const from = new Date(Date.now() - (rangeDays.value - 1) * 86400_000)
  const iso = (d: Date) => d.toISOString().slice(0, 10)
  return `from=${iso(from)}&to=${iso(to)}`
}

const filterQS = () =>
  filter.value ? `&filter=${encodeURIComponent(`${filter.value.dim}:${filter.value.value}`)}` : ''

function setFilter(dim: string, value: string) {
  filter.value = filter.value?.dim === dim && filter.value.value === value ? null : { dim, value }
}

const filterLabel = computed(() => {
  if (!filter.value) return ''
  const panel = PANELS.find((p) => p.key === filter.value!.dim)
  return `${panel?.title ?? filter.value.dim}: ${filter.value.value}`
})

async function load() {
  if (!siteId.value) return
  loading.value = true
  const id = siteId.value
  // hidden modules are not fetched at all (funnels especially are heavier queries)
  const activePanels = PANELS.filter((p) => show(p.key))
  const [s, a, g, f, v, ...panels] = await Promise.all([
    api<Stats>(`/sites/${id}/stats?${rangeParams()}${filterQS()}`),
    api<{ annotations: Annotation[] }>(`/sites/${id}/annotations?${rangeParams()}`),
    show('goals') ? api<{ goals: GoalRow[] }>(`/sites/${id}/goals?${rangeParams()}`) : null,
    show('funnels') ? api<{ funnels: FunnelRow[] }>(`/sites/${id}/funnels?${rangeParams()}`) : null,
    show('vitals') ? api<Vitals>(`/sites/${id}/vitals?${rangeParams()}`) : null,
    ...activePanels.map((p) =>
      api<{ rows: BreakdownRow[] }>(`/sites/${id}/breakdown?dimension=${p.key}&${rangeParams()}&limit=8${filterQS()}`)
    ),
  ])
  stats.value = s as Stats
  annotations.value = (a as { annotations: Annotation[] }).annotations
  goals.value = g ? (g as { goals: GoalRow[] }).goals : []
  funnels.value = f ? (f as { funnels: FunnelRow[] }).funnels : []
  vitals.value = v ? (v as Vitals) : null
  breakdowns.value = Object.fromEntries(activePanels.map((p, i) => [p.key, panels[i].rows]))
  loading.value = false
}

async function pollLive() {
  if (!siteId.value) return
  try {
    live.value = (await api<{ visitors: number }>(`/sites/${siteId.value}/live`)).visitors
  } catch {}
}

let liveTimer: ReturnType<typeof setInterval>

onMounted(async () => {
  sites.value = await api<Site[]>('/sites')
  await load()
  await pollLive()
  liveTimer = setInterval(pollLive, 15_000)
})
onBeforeUnmount(() => clearInterval(liveTimer))

watch([siteId, rangeDays, filter], load)

async function addNote() {
  if (!noteText.value || !siteId.value) return
  await api(`/sites/${siteId.value}/annotations`, {
    method: 'POST',
    body: JSON.stringify({ day: noteDay.value, text: noteText.value }),
  })
  noteText.value = ''
  noting.value = false
  annotations.value = (
    await api<{ annotations: Annotation[] }>(`/sites/${siteId.value}/annotations?${rangeParams()}`)
  ).annotations
}

async function removeNote(id: number) {
  await api(`/sites/${siteId.value}/annotations/${id}`, { method: 'DELETE' })
  annotations.value = annotations.value.filter((a) => a.id !== id)
}

async function logout() {
  try {
    await api('/auth/logout', { method: 'POST' })
  } catch {}
  setToken(null)
  router.push('/login')
}
</script>

<template>
  <div class="mx-auto max-w-6xl px-5 py-6">
    <header class="mb-8 flex flex-wrap items-center gap-x-3 gap-y-2">
      <div class="flex items-baseline gap-3">
        <h1 class="text-lg font-semibold tracking-tight">melytics</h1>

        <select
          v-if="sites.length > 1"
          :value="siteId"
          class="rounded-lg bg-[var(--surface)] px-3 py-1.5 text-sm outline-none"
          @change="router.push(`/${($event.target as HTMLSelectElement).value}`)"
        >
          <option v-for="s in sites" :key="s.id" :value="s.id">{{ s.domain }}</option>
        </select>
        <span v-else-if="site" class="text-sm text-[var(--ink-3)]">{{ site.domain }}</span>
      </div>

      <span
        v-if="filter"
        class="flex items-center gap-1.5 rounded-full bg-[var(--accent-soft)] px-3 py-1 text-sm font-medium text-[var(--accent)]"
      >
        <span class="max-w-56 truncate">{{ filterLabel }}</span>
        <button class="leading-none hover:opacity-70" title="Clear filter" @click="filter = null">×</button>
      </span>

      <div class="ml-auto flex items-center gap-2">
        <div class="flex items-center gap-1 rounded-lg bg-[var(--surface)] p-1">
          <button
            v-for="r in RANGES"
            :key="r.days"
            class="rounded-md px-3 py-1 text-sm"
            :class="rangeDays === r.days ? 'bg-[var(--accent-soft)] text-[var(--accent)] font-medium' : 'text-[var(--ink-2)]'"
            @click="rangeDays = r.days"
          >
            {{ r.label }}
          </button>
        </div>

        <SharePanel v-if="siteId" :key="siteId" :site-id="siteId" />
        <SettingsPanel
          :modules="MODULES"
          :hidden="hidden"
          :density="density"
          @toggle="toggleModule"
          @density="setDensity"
          @signout="logout"
        />
      </div>
    </header>

    <main v-if="stats" class="space-y-5" :class="{ compact: density === 'compact' }">
      <StatStrip :stats="stats" :metric="metric" :live="live" :vitals="vitals" @update:metric="metric = $event" />

      <section class="card p-5">
        <div class="flex items-baseline gap-3 mb-2">
          <span class="text-sm text-[var(--ink-2)] capitalize">{{ metric }}</span>
          <span v-if="delta !== null" class="text-xs tabular-nums text-[var(--ink-3)]">vs previous {{ rangeDays }}d</span>
          <button class="ml-auto self-start text-sm text-[var(--ink-3)] hover:text-[var(--accent)]" @click="noting = !noting">
            {{ noting ? 'Cancel' : '＋ Note' }}
          </button>
        </div>

        <form v-if="noting" class="flex gap-2 mb-3" @submit.prevent="addNote">
          <input
            v-model="noteDay"
            type="date"
            class="rounded-lg px-3 py-1.5 text-sm bg-[var(--bg)] outline-none focus:ring-2 ring-[var(--accent)]"
          />
          <input
            v-model="noteText"
            placeholder="What happened? (deploy, launch, campaign…)"
            class="flex-1 rounded-lg px-3 py-1.5 text-sm bg-[var(--bg)] outline-none focus:ring-2 ring-[var(--accent)] placeholder:text-[var(--ink-3)]"
          />
          <button class="rounded-lg px-3 py-1.5 text-sm text-white bg-[var(--accent)]">Save</button>
        </form>

        <TimeChart :series="stats.series" :previous="stats.previous_series" :metric="metric" :annotations="annotations" />

        <div v-if="annotations.length" class="mt-2 flex flex-wrap gap-1.5">
          <span
            v-for="a in annotations"
            :key="a.id"
            class="group flex items-center gap-1.5 rounded-full bg-[var(--bg)] px-2.5 py-0.5 text-xs text-[var(--ink-2)]"
          >
            <span class="text-[var(--ink-3)]">{{ a.day.slice(5) }}</span>
            {{ a.text }}
            <button
              class="opacity-0 group-hover:opacity-100 text-[var(--ink-3)] hover:text-[var(--down)]"
              title="Delete note"
              @click="removeNote(a.id)"
            >
              ×
            </button>
          </span>
        </div>
      </section>

      <div v-if="show('goals') || show('funnels')" class="grid gap-5 lg:grid-cols-2">
        <GoalsCard v-if="show('goals')" :site-id="siteId" :goals="goals" @changed="load" />
        <FunnelsCard v-if="show('funnels')" :site-id="siteId" :funnels="funnels" @changed="load" />
      </div>

      <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        <div
          v-for="p in orderedPanels"
          :key="p.key"
          draggable="true"
          class="rounded-[14px] transition-opacity"
          :class="{ 'opacity-40': dragKey === p.key, 'ring-2 ring-[var(--accent)]': overKey === p.key && dragKey && dragKey !== p.key }"
          @dragstart="dragKey = p.key"
          @dragend=";(dragKey = null), (overKey = null)"
          @dragover.prevent="overKey = p.key"
          @dragleave="overKey === p.key && (overKey = null)"
          @drop.prevent="dropOn(p.key)"
        >
          <VitalsCard v-if="p.key === 'vitals' && vitals" :vitals="vitals" />
          <BreakdownCard
            v-else-if="p.key !== 'vitals'"
            :title="p.title"
            :rows="breakdowns[p.key] ?? []"
            :dim="p.key"
            clickable
            :selected="filter?.dim === p.key ? filter.value : null"
            @select="(v) => setFilter(p.key, v)"
          />
        </div>
      </div>
    </main>

    <p v-else-if="loading" class="text-[var(--ink-3)]">Loading…</p>
  </div>
</template>
