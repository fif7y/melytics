<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, setToken, type Annotation, type Attribution, type BreakdownRow, type CohortRow, type Loyalty, type Me, type Retention, type Site, type Stats, type TimeToConvert } from '../lib/api'
import TimeChart from '../components/TimeChart.vue'
import BreakdownCard from '../components/BreakdownCard.vue'
import StatStrip from '../components/StatStrip.vue'
import GoalsCard, { type GoalRow } from '../components/GoalsCard.vue'
import FunnelsCard, { type FunnelRow } from '../components/FunnelsCard.vue'
import VitalsCard, { type Vitals } from '../components/VitalsCard.vue'
import RetentionCard from '../components/RetentionCard.vue'
import CohortCard from '../components/CohortCard.vue'
import LoyaltyCard from '../components/LoyaltyCard.vue'
import AttributionCard from '../components/AttributionCard.vue'
import TimeToConvertCard from '../components/TimeToConvertCard.vue'
import SharePanel from '../components/SharePanel.vue'
import { theme, accent } from '../lib/theme'
import { usePersistedRef, safeJson } from '../lib/persist'
import { useDateRange, todayIso, RANGES, RANGE_PRESETS } from '../lib/useDateRange'
import SettingsPanel from '../components/SettingsPanel.vue'
import AccountPanel from '../components/AccountPanel.vue'
import SetupWizard from '../components/SetupWizard.vue'

const route = useRoute()
const router = useRouter()

const sites = ref<Site[]>([])
const stats = ref<Stats | null>(null)
const goals = ref<GoalRow[]>([])
const funnels = ref<FunnelRow[]>([])
const vitals = ref<Vitals | null>(null)
const retention = ref<Retention | null>(null)
const cohorts = ref<CohortRow[] | null>(null)
const loyalty = ref<Loyalty | null>(null)
const attribution = ref<Attribution | null>(null)
const ttc = ref<TimeToConvert | null>(null)
const annotations = ref<Annotation[]>([])
const noting = ref(false)
const noteDay = ref(new Date().toISOString().slice(0, 10))
const noteText = ref('')
const breakdowns = ref<Record<string, BreakdownRow[]>>({})
const live = ref<number | null>(null)
const livePages = ref<BreakdownRow[]>([])
const metric = ref<'visitors' | 'pageviews'>('visitors')
const filter = ref<{ dim: string; value: string } | null>(null)
const loading = ref(true)

const {
  rangeDays,
  customRange,
  pickingRange,
  pickFrom,
  pickTo,
  openRangePicker,
  applyPresetChip,
  applyCustomRange,
  setPresetRange,
  customLabel,
  rangeParams,
} = useDateRange()

// inert panels are observation-only: their dimensions can't cross-filter
const PANELS: { key: string; title: string; inert?: boolean }[] = [
  { key: 'page', title: 'Pages' },
  { key: 'entry_page', title: 'Entry pages', inert: true },
  { key: 'exit_page', title: 'Exit pages', inert: true },
  { key: 'referrer', title: 'Referrers' },
  { key: 'channel', title: 'Channels', inert: true },
  { key: 'utm_source', title: 'Sources' },
  { key: 'utm_medium', title: 'Mediums' },
  { key: 'country', title: 'Countries' },
  { key: 'device', title: 'Devices' },
  { key: 'browser', title: 'Browsers' },
  { key: 'utm_campaign', title: 'Campaigns' },
  { key: 'event', title: 'Events' },
  { key: 'outbound', title: 'Outbound links', inert: true },
  { key: 'download', title: 'Downloads', inert: true },
  { key: 'not_found', title: '404s', inert: true },
]

const MODULES = [
  { key: 'live', label: 'Live pages' },
  { key: 'vitals', label: 'Web Vitals' },
  { key: 'retention', label: 'Retention', tier2: true },
  { key: 'cohorts', label: 'Cohorts', tier2: true },
  { key: 'loyalty', label: 'Loyalty', tier2: true },
  { key: 'attribution', label: 'Attribution', tier2: true },
  { key: 'ttc', label: 'Time to convert', tier2: true },
  { key: 'goals', label: 'Goals' },
  { key: 'funnels', label: 'Funnels' },
  ...PANELS.map((p) => ({ key: p.key, label: p.title })),
]

const hidden = usePersistedRef<string[]>('melytics_hidden', (raw) => (safeJson(raw) as string[]) ?? [], true)
const show = (key: string) => !hidden.value.includes(key)
// Tier-2 modules only render (and fetch) when the site has tier-2 tracking on
const TIER2_KEYS = MODULES.filter((m) => 'tier2' in m && m.tier2).map((m) => m.key)
const visible = (key: string) => show(key) && (!TIER2_KEYS.includes(key) || (site.value?.tier2_enabled ?? false))

// Drag-to-reorder breakdown cards, persisted like the hide-toggles
const order = usePersistedRef<string[]>('melytics_order', (raw) => (safeJson(raw) as string[]) ?? [], true)
// Vitals lives in the same reorderable grid as the breakdowns
// Default grid order for fresh installs — the curated layout (saved 2026-08-26)
const GRID_DEFAULT = ['page', 'live', 'country', 'referrer', 'device', 'browser', 'vitals', 'entry_page', 'exit_page', 'channel', 'not_found', 'outbound', 'download', 'utm_source', 'utm_medium', 'utm_campaign', 'event', 'loyalty', 'retention', 'attribution', 'ttc', 'cohorts']
const SPECIAL_TITLES: Record<string, string> = { live: 'Live', vitals: 'Web Vitals', retention: 'Retention', cohorts: 'Cohorts', loyalty: 'Loyalty', attribution: 'Attribution', ttc: 'Time to convert' }
const GRID_ITEMS = GRID_DEFAULT.map((k) => PANELS.find((p) => p.key === k) ?? { key: k, title: SPECIAL_TITLES[k] })
// Sort position of a grid card: its saved order, else after everything saved,
// in default-grid order. Shared by both drag surfaces and the settings list.
const gridIdx = (k: string) => {
  const i = order.value.indexOf(k)
  return i === -1 ? 100 + GRID_ITEMS.findIndex((p) => p.key === k) : i
}
const orderedPanels = computed(() =>
  GRID_ITEMS.filter((p) => visible(p.key)).slice().sort((a, b) => gridIdx(a.key) - gridIdx(b.key))
)
const dragKey = ref<string | null>(null)
const overKey = ref<string | null>(null)

// Settings lists modules in on-screen order: goals/funnels row first, then the grid's order
const orderedModules = computed(() => {
  const idx = (k: string) => (k === 'goals' ? -2 : k === 'funnels' ? -1 : gridIdx(k))
  return MODULES.slice().sort((a, b) => idx(a.key) - idx(b.key))
})
// Move one grid card before another in the persisted order. Operates on the
// full ordered key list (hidden cards included) so both drag surfaces — the
// grid and the settings list — write the same store.
function moveKey(from: string, to: string) {
  // goals/funnels live in a fixed row above the grid — not reorderable
  if (from === to || !GRID_ITEMS.some((p) => p.key === from) || !GRID_ITEMS.some((p) => p.key === to)) return
  const keys = GRID_ITEMS.map((p) => p.key).sort((a, b) => gridIdx(a) - gridIdx(b))
  keys.splice(keys.indexOf(to), 0, keys.splice(keys.indexOf(from), 1)[0])
  order.value = keys
}

function dropOn(target: string) {
  if (dragKey.value) moveKey(dragKey.value, target)
}

// Chart mark style, shared by the big chart and the stat-strip sparklines
const CHART_STYLES = [
  { key: 'smooth', label: 'Smooth' },
  { key: 'linear', label: 'Linear' },
  { key: 'step', label: 'Step' },
  { key: 'bars', label: 'Bars' },
  { key: 'glow', label: 'Glow' },
] as const
type ChartStyle = (typeof CHART_STYLES)[number]['key']
const chartStyle = usePersistedRef<ChartStyle>('melytics_chart_style', (raw) =>
  CHART_STYLES.some((s) => s.key === raw) ? (raw as ChartStyle) : 'smooth'
)
const chartStyleMenu = ref(false)
function setChartStyle(k: ChartStyle) {
  chartStyle.value = k
  chartStyleMenu.value = false
}

// Density: compact tightens card padding and row spacing
const density = usePersistedRef<'comfy' | 'compact'>('melytics_density', (raw) => (raw === 'compact' ? 'compact' : 'comfy'))
function setDensity(d: 'comfy' | 'compact') {
  density.value = d
}

function toggleModule(key: string) {
  hidden.value = show(key) ? [...hidden.value, key] : hidden.value.filter((k) => k !== key)
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
  if (!siteId.value) {
    loading.value = false
    return
  }
  loading.value = true
  const id = siteId.value
  // hidden modules are not fetched at all (funnels especially are heavier queries)
  const activePanels = PANELS.filter((p) => show(p.key))
  // keyed requests, not a positional destructure — inserting one can't shift the rest
  const req = {
    stats: api<Stats>(`/sites/${id}/stats?${rangeParams()}${filterQS()}`),
    annotations: api<{ annotations: Annotation[] }>(`/sites/${id}/annotations?${rangeParams()}`),
    goals: show('goals') ? api<{ goals: GoalRow[] }>(`/sites/${id}/goals?${rangeParams()}`) : null,
    funnels: show('funnels') ? api<{ funnels: FunnelRow[] }>(`/sites/${id}/funnels?${rangeParams()}`) : null,
    vitals: show('vitals') ? api<Vitals>(`/sites/${id}/vitals?${rangeParams()}`) : null,
    retention: visible('retention') ? api<Retention>(`/sites/${id}/retention?${rangeParams()}`) : null,
    cohorts: visible('cohorts') ? api<{ cohorts: CohortRow[] }>(`/sites/${id}/cohorts`) : null,
    loyalty: visible('loyalty') ? api<Loyalty>(`/sites/${id}/loyalty?${rangeParams()}`) : null,
    attribution: visible('attribution') ? api<Attribution>(`/sites/${id}/attribution?${rangeParams()}`) : null,
    ttc: visible('ttc') ? api<TimeToConvert>(`/sites/${id}/time-to-convert?${rangeParams()}`) : null,
    panels: Promise.all(
      activePanels.map((p) =>
        api<{ rows: BreakdownRow[] }>(`/sites/${id}/breakdown?dimension=${p.key}&${rangeParams()}&limit=8${filterQS()}`)
      )
    ),
  }
  const r = Object.fromEntries(
    await Promise.all(Object.entries(req).map(async ([k, p]) => [k, await p]))
  ) as { [K in keyof typeof req]: Awaited<(typeof req)[K]> }
  stats.value = r.stats
  annotations.value = r.annotations.annotations
  goals.value = r.goals?.goals ?? []
  funnels.value = r.funnels?.funnels ?? []
  vitals.value = r.vitals
  retention.value = r.retention
  cohorts.value = r.cohorts?.cohorts ?? null
  loyalty.value = r.loyalty
  attribution.value = r.attribution
  ttc.value = r.ttc
  breakdowns.value = Object.fromEntries(activePanels.map((p, i) => [p.key, r.panels[i].rows]))
  loading.value = false
}

async function pollLive() {
  if (!siteId.value) return
  try {
    const r = await api<{ visitors: number; pages: { path: string; visitors: number }[] }>(`/sites/${siteId.value}/live`)
    live.value = r.visitors
    livePages.value = r.pages.map((p) => ({ value: p.path, pageviews: p.visitors, visitors: p.visitors }))
  } catch {}
}

let liveTimer: ReturnType<typeof setInterval>

onMounted(async () => {
  ;[sites.value, me.value] = await Promise.all([api<Site[]>('/sites'), api<Me>('/auth/me')])
  await load()
  await pollLive()
  liveTimer = setInterval(pollLive, 15_000)
})

const me = ref<Me | null>(null)
const wizard = ref<InstanceType<typeof SetupWizard> | null>(null)

// Real pages/events for the goal & wizard builders — contextual suggestions
const targets = ref<{ pages: string[]; events: string[] }>({ pages: [], events: [] })
watch(
  siteId,
  async (id) => {
    if (!id) return
    try {
      targets.value = await api<{ pages: string[]; events: string[] }>(`/sites/${id}/targets`)
    } catch {}
  },
  { immediate: true }
)
const resent = ref(false)
async function resendVerification() {
  await api('/auth/resend-verification', { method: 'POST' })
  resent.value = true
}

async function setNotify(field: 'digest_enabled' | 'alerts_enabled', on: boolean) {
  if (!site.value) return
  const updated = await api<Site>(`/sites/${site.value.id}`, {
    method: 'PATCH',
    body: JSON.stringify({ [field]: on }),
  })
  sites.value = sites.value.map((s) => (s.id === updated.id ? updated : s))
}

async function addSite(payload: { name: string; domain: string }) {
  try {
    const created = await api<Site>('/sites', {
      method: 'POST',
      body: JSON.stringify({ ...payload, timezone: Intl.DateTimeFormat().resolvedOptions().timeZone }),
    })
    sites.value = [...sites.value, created]
    router.push(`/${created.id}`)
  } catch (e) {
    alert(e instanceof Error ? e.message : 'Could not create site')
  }
}

async function deleteSite() {
  if (!site.value) return
  const s = site.value
  if (!confirm(`Delete ${s.domain} and ALL its analytics data? This cannot be undone.`)) return
  await api(`/sites/${s.id}`, { method: 'DELETE' })
  sites.value = sites.value.filter((x) => x.id !== s.id)
  router.push(sites.value.length ? `/${sites.value[0].id}` : '/')
}
onBeforeUnmount(() => clearInterval(liveTimer))

watch([siteId, rangeDays, filter, customRange], load)

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

async function setTier2(on: boolean) {
  if (!site.value) return
  const updated = await api<Site>(`/sites/${site.value.id}`, {
    method: 'PATCH',
    body: JSON.stringify({ tier2_enabled: on }),
  })
  sites.value = sites.value.map((s) => (s.id === updated.id ? updated : s))
  // tier-2 modules appear/disappear with the toggle — refetch so they have data
  if (on) load()
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
    <!-- Three groups: identity/context left, range centered, config right -->
    <header class="mb-8 grid items-center gap-x-3 gap-y-2 md:grid-cols-[1fr_auto_1fr]">
      <div class="flex flex-wrap items-center gap-3">
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
      </div>

      <div class="flex items-center gap-1 rounded-lg bg-[var(--surface)] p-1 justify-self-start md:justify-self-center">
        <button
          v-for="r in RANGES"
          :key="r.days"
          class="rounded-md px-3 py-1 text-sm"
          :class="!customRange && rangeDays === r.days ? 'bg-[var(--accent-soft)] text-[var(--accent)] font-medium' : 'text-[var(--ink-2)]'"
          @click="setPresetRange(r.days)"
        >
          {{ r.label }}
        </button>
        <button
          class="rounded-md px-3 py-1 text-sm tabular-nums"
          :class="customRange ? 'bg-[var(--accent-soft)] text-[var(--accent)] font-medium' : 'text-[var(--ink-2)]'"
          @click="openRangePicker"
        >
          {{ customLabel }}
        </button>
      </div>

      <div class="flex items-center gap-2 justify-self-end">
        <SharePanel v-if="siteId" :key="siteId" :site-id="siteId" />
        <SettingsPanel
          :modules="orderedModules"
          :hidden="hidden"
          :density="density"
          :tier2="site?.tier2_enabled ?? false"
          @toggle="toggleModule"
          @density="setDensity"
          @tier2="setTier2"
          @reorder="moveKey"
        />
        <AccountPanel
          :site="site ?? null"
          :me="me"
          @notify="setNotify"
          @add-site="addSite"
          @delete-site="deleteSite"
          @signout="logout"
        />
      </div>
    </header>

    <SetupWizard v-if="siteId" ref="wizard" :site-id="siteId" :has-goals="goals.length > 0" :targets="targets" @created="load" />

    <Teleport to="body">
      <div v-if="pickingRange" class="fixed inset-0 z-50 grid place-items-center bg-black/25 p-6" @click.self="pickingRange = false" @keydown.esc="pickingRange = false">
        <form class="w-full max-w-xs rounded-[14px] bg-[var(--surface)] p-5 shadow-2xl" @submit.prevent="applyCustomRange">
          <h2 class="mb-4 text-sm font-semibold">Custom range</h2>
          <div class="mb-4 flex flex-wrap gap-1.5">
            <button
              v-for="p in RANGE_PRESETS"
              :key="p.label"
              type="button"
              class="rounded-full bg-[var(--bg)] px-3 py-1 text-xs text-[var(--ink-2)] hover:text-[var(--ink)]"
              @click="applyPresetChip(p)"
            >
              {{ p.label }}
            </button>
          </div>
          <label class="mb-1 block text-xs text-[var(--ink-3)]" for="range-from">From</label>
          <input
            id="range-from"
            v-model="pickFrom"
            type="date"
            required
            :max="todayIso()"
            class="mb-3 w-full rounded-lg bg-[var(--bg)] px-3 py-2 text-sm outline-none focus:ring-2 ring-[var(--accent)]"
          />
          <label class="mb-1 block text-xs text-[var(--ink-3)]" for="range-to">To</label>
          <input
            id="range-to"
            v-model="pickTo"
            type="date"
            required
            :max="todayIso()"
            class="mb-5 w-full rounded-lg bg-[var(--bg)] px-3 py-2 text-sm outline-none focus:ring-2 ring-[var(--accent)]"
          />
          <div class="flex gap-2">
            <button class="flex-1 rounded-lg bg-[var(--accent)] py-2 text-sm font-medium text-white">Apply</button>
            <button type="button" class="rounded-lg px-4 py-2 text-sm text-[var(--ink-3)]" @click="pickingRange = false">Cancel</button>
          </div>
        </form>
      </div>
    </Teleport>

    <div
      v-if="me && !me.verified"
      class="mb-6 flex flex-wrap items-center gap-3 rounded-[14px] bg-[var(--accent-soft)] px-4 py-3 text-sm"
    >
      <span>Verify your email to add sites — we sent a link to <b>{{ me.email }}</b>.</span>
      <button
        v-if="!resent"
        class="font-medium text-[var(--accent)] hover:opacity-80"
        @click="resendVerification"
      >
        Resend
      </button>
      <span v-else class="text-[var(--ink-3)]">Sent again — check your inbox.</span>
    </div>

    <main v-if="!loading && !sites.length" class="mx-auto max-w-sm py-24 text-center">
      <h2 class="mb-2 text-lg font-semibold">Add your first site</h2>
      <p class="mb-6 text-sm text-[var(--ink-3)]">Create a site in Settings (top right), paste the snippet, and stats appear within a minute.</p>
    </main>

    <main v-else-if="stats" class="space-y-5" :class="{ compact: density === 'compact' }">
      <StatStrip :stats="stats" :metric="metric" :live="live" :line-style="chartStyle" @update:metric="metric = $event" />

      <section class="card p-5">
        <div class="flex items-baseline gap-3 mb-2">
          <span class="text-sm text-[var(--ink-2)] capitalize">{{ metric }}</span>
          <span v-if="delta !== null" class="text-xs tabular-nums text-[var(--ink-3)]">vs {{ customRange ? 'previous period' : rangeDays === 1 ? 'yesterday' : `previous ${rangeDays}d` }}</span>
          <div class="relative ml-auto self-start">
            <button
              class="flex h-6 w-6 items-center justify-center rounded-md text-[var(--ink-3)] hover:bg-[var(--bg)] hover:text-[var(--ink)]"
              title="Chart style"
              aria-label="Choose chart style"
              @click="chartStyleMenu = !chartStyleMenu"
            >
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                <path d="M4 6h16M4 12h10M4 18h5" />
              </svg>
            </button>
            <div v-if="chartStyleMenu" class="absolute right-0 top-full z-20 mt-1 w-32 rounded-xl bg-[var(--surface)] py-1 shadow-xl">
              <button
                v-for="s in CHART_STYLES"
                :key="s.key"
                class="flex w-full items-center px-3 py-1.5 text-left text-sm hover:bg-[var(--bg)]"
                :class="chartStyle === s.key ? 'text-[var(--accent)] font-medium' : ''"
                @click="setChartStyle(s.key)"
              >
                {{ s.label }}
              </button>
            </div>
          </div>
          <button class="self-start text-sm text-[var(--ink-3)] hover:text-[var(--accent)]" @click="noting = !noting">
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

        <TimeChart :key="`${theme}-${accent}`" :series="stats.series" :previous="stats.previous_series" :metric="metric" :annotations="annotations" :line-style="chartStyle" />

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
        <GoalsCard v-if="show('goals')" class="h-full" :site-id="siteId" :goals="goals" :targets="targets" @changed="load" @assist="wizard?.show(goals.length ? 1 : 0)" />
        <FunnelsCard v-if="show('funnels')" class="h-full" :site-id="siteId" :funnels="funnels" :targets="targets" @changed="load" @assist="wizard?.show(2)" />
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
          <BreakdownCard
            v-if="p.key === 'live'"
            class="h-full"
            title="Live"
            live
            :rows="livePages"
            empty="No one on the site right now"
          />
          <VitalsCard v-else-if="p.key === 'vitals' && vitals" class="h-full" :vitals="vitals" />
          <RetentionCard
            v-else-if="p.key === 'retention' && retention"
            class="h-full"
            :retention="retention"
            :tier2-enabled="site?.tier2_enabled ?? false"
          />
          <CohortCard
            v-else-if="p.key === 'cohorts' && cohorts"
            class="h-full"
            :cohorts="cohorts"
            :tier2-enabled="site?.tier2_enabled ?? false"
          />
          <LoyaltyCard
            v-else-if="p.key === 'loyalty' && loyalty"
            class="h-full"
            :loyalty="loyalty"
            :tier2-enabled="site?.tier2_enabled ?? false"
          />
          <AttributionCard v-else-if="p.key === 'attribution' && attribution" class="h-full" :attribution="attribution" />
          <TimeToConvertCard v-else-if="p.key === 'ttc' && ttc" class="h-full" :ttc="ttc" />
          <BreakdownCard
            v-else-if="!['vitals', 'retention', 'live', 'cohorts', 'loyalty', 'attribution', 'ttc'].includes(p.key)"
            class="h-full"
            :title="p.title"
            :rows="breakdowns[p.key] ?? []"
            :dim="p.key"
            :clickable="!p.inert"
            :selected="filter?.dim === p.key ? filter.value : null"
            @select="(v) => !p.inert && setFilter(p.key, v)"
          />
        </div>
      </div>
    </main>

    <p v-else-if="loading" class="text-[var(--ink-3)]">Loading…</p>
  </div>
</template>
