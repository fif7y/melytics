<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, setToken, type Annotation, type Attribution, type Bots, type BreakdownRow, type CohortRow, type Loyalty, type Me, type Retention, type Site, type Stats, type TimeToConvert } from '../lib/api'
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
import BotsCard from '../components/BotsCard.vue'
import AppFooter from '../components/AppFooter.vue'
import SharePanel from '../components/SharePanel.vue'
import { theme, accent, accentHex, scopeAccent } from '../lib/theme'
import { useSiteScopedRef, safeJson } from '../lib/persist'
import { useDateRange, todayIso, RANGES, RANGE_PRESETS } from '../lib/useDateRange'
import ModulesPanel from '../components/ModulesPanel.vue'
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
const bots = ref<Bots | null>(null)
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
  { key: 'bots', label: 'Bots' },
  { key: 'retention', label: 'Retention', tier2: true },
  { key: 'cohorts', label: 'Cohorts', tier2: true },
  { key: 'loyalty', label: 'Loyalty', tier2: true },
  { key: 'attribution', label: 'Attribution', tier2: true },
  { key: 'ttc', label: 'Time to convert', tier2: true },
  { key: 'goals', label: 'Goals' },
  { key: 'funnels', label: 'Funnels' },
  ...PANELS.map((p) => ({ key: p.key, label: p.title })),
]

const siteId = computed(() => Number(route.params.siteId) || sites.value[0]?.id)
const site = computed(() => sites.value.find((s) => s.id === siteId.value))
// Accent is per-site too — rescope it whenever the viewed site changes
watch(siteId, (id) => scopeAccent(id), { immediate: true })

// Module visibility + order are per-site (falls back to the legacy global keys)
const hidden = useSiteScopedRef<string[]>('melytics_hidden', siteId, (raw) => (safeJson(raw) as string[]) ?? [], true)
const show = (key: string) => !hidden.value.includes(key)
// Tier-2 modules only render (and fetch) when the site has tier-2 tracking on
const TIER2_KEYS = MODULES.filter((m) => 'tier2' in m && m.tier2).map((m) => m.key)
const visible = (key: string) => show(key) && (!TIER2_KEYS.includes(key) || (site.value?.tier2_enabled ?? false))

// Drag-to-reorder breakdown cards, persisted like the hide-toggles
const order = useSiteScopedRef<string[]>('melytics_order', siteId, (raw) => (safeJson(raw) as string[]) ?? [], true)
// Goals/funnels live in their own fixed row above the grid — swappable pair
const rowOrder = useSiteScopedRef<string[]>(
  'melytics_row_order',
  siteId,
  (raw) => {
    const v = safeJson(raw) as string[]
    return Array.isArray(v) && v.length === 2 && v.includes('goals') && v.includes('funnels') ? v : ['goals', 'funnels']
  },
  true
)
// Vitals lives in the same reorderable grid as the breakdowns
// Default grid order for fresh installs — the curated layout (saved 2026-08-26)
const GRID_DEFAULT = ['page', 'live', 'country', 'referrer', 'device', 'browser', 'vitals', 'entry_page', 'exit_page', 'channel', 'not_found', 'outbound', 'download', 'utm_source', 'utm_medium', 'utm_campaign', 'event', 'bots', 'loyalty', 'retention', 'attribution', 'ttc', 'cohorts']
const SPECIAL_TITLES: Record<string, string> = { live: 'Live', vitals: 'Web Vitals', bots: 'Bots', retention: 'Retention', cohorts: 'Cohorts', loyalty: 'Loyalty', attribution: 'Attribution', ttc: 'Time to convert' }
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
  const idx = (k: string) => (rowOrder.value.includes(k) ? -2 + rowOrder.value.indexOf(k) : gridIdx(k))
  return MODULES.slice().sort((a, b) => idx(a.key) - idx(b.key))
})
// Move one grid card before another in the persisted order. Operates on the
// full ordered key list (hidden cards included) so both drag surfaces — the
// grid and the settings list — write the same store.
function moveKey(from: string, to: string) {
  if (from === to) return
  // goals/funnels swap within their own row, never mix with the grid
  if (rowOrder.value.includes(from) && rowOrder.value.includes(to)) {
    rowOrder.value = rowOrder.value.slice().reverse()
    return
  }
  if (!GRID_ITEMS.some((p) => p.key === from) || !GRID_ITEMS.some((p) => p.key === to)) return
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
const chartStyle = useSiteScopedRef<ChartStyle>('melytics_chart_style', siteId, (raw) =>
  CHART_STYLES.some((s) => s.key === raw) ? (raw as ChartStyle) : 'smooth'
)
const chartStyleMenu = ref(false)
function setChartStyle(k: ChartStyle) {
  chartStyle.value = k
  chartStyleMenu.value = false
}

// Density: compact tightens card padding and row spacing
const density = useSiteScopedRef<'comfy' | 'compact'>('melytics_density', siteId, (raw) => (raw === 'compact' ? 'compact' : 'comfy'))
function setDensity(d: 'comfy' | 'compact') {
  density.value = d
}

function toggleModule(key: string) {
  hidden.value = show(key) ? [...hidden.value, key] : hidden.value.filter((k) => k !== key)
  load()
}

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

// Generation guard: rapid site/range switches fire overlapping loads, and a
// slow older response must never overwrite a newer site's data. The in-flight
// key dedupes the mount double-fire (onMounted load + the siteId watcher).
let loadGen = 0
let inflightKey = ''

// silent = background refresh: update data in place, never flash the spinner
async function load(silent = false) {
  if (!siteId.value) {
    loading.value = false
    return
  }
  const key = `${siteId.value}|${rangeParams()}|${filterQS()}`
  if (key === inflightKey) return
  inflightKey = key
  if (!silent) loading.value = true
  const gen = ++loadGen
  const id = siteId.value
  // hidden modules are not fetched at all (funnels especially are heavier queries).
  // One batched request: each extra request boots the whole framework on shared
  // hosting, so a site switch used to cost ~16 round trips.
  const activePanels = PANELS.filter((p) => show(p.key))
  const modules: string[] = [
    ...['goals', 'funnels', 'vitals', 'bots'].filter(show),
    ...['retention', 'cohorts', 'loyalty', 'attribution', 'ttc'].filter(visible),
  ]
  const r = await api<{
    stats: Stats
    annotations: Annotation[]
    goals: GoalRow[] | null
    funnels: FunnelRow[] | null
    vitals: Vitals | null
    bots: Bots | null
    retention: Retention | null
    cohorts: CohortRow[] | null
    loyalty: Loyalty | null
    attribution: Attribution | null
    ttc: TimeToConvert | null
    breakdowns: Record<string, BreakdownRow[]>
  }>(
    `/sites/${id}/dashboard?${rangeParams()}${filterQS()}&limit=8` +
      `&modules=${modules.join(',')}&panels=${activePanels.map((p) => p.key).join(',')}`
  ).finally(() => {
    if (inflightKey === key) inflightKey = ''
  })
  if (gen !== loadGen) return
  stats.value = r.stats
  annotations.value = r.annotations
  goals.value = r.goals ?? []
  funnels.value = r.funnels ?? []
  vitals.value = r.vitals
  bots.value = r.bots
  retention.value = r.retention
  cohorts.value = r.cohorts
  loyalty.value = r.loyalty
  attribution.value = r.attribution
  ttc.value = r.ttc
  breakdowns.value = Object.fromEntries(activePanels.map((p) => [p.key, r.breakdowns[p.key] ?? []]))
  loading.value = false
}

// Overlap guard + visibility gate: a poll slower than the interval must not
// stack a second request behind it, and hidden tabs shouldn't poll at all.
let livePolling = false

async function pollLive(initial = false) {
  // initial=true (mount, site switch) always fetches — the gate only applies
  // to the background interval, so a hidden or oddly-reporting tab still paints
  if (!siteId.value || livePolling || (document.hidden && !initial)) return
  livePolling = true
  const id = siteId.value
  try {
    const r = await api<{ visitors: number; pages: { path: string; visitors: number }[] }>(`/sites/${id}/live`)
    if (id !== siteId.value) return // switched sites mid-flight — stale numbers
    live.value = r.visitors
    livePages.value = r.pages.map((p) => ({ value: p.path, pageviews: p.visitors, visitors: p.visitors }))
  } catch {} finally {
    livePolling = false
  }
}

// The 15s interval alone leaves the previous site's live numbers on screen
// after a switch — reset and re-poll immediately.
watch(siteId, () => {
  live.value = 0
  livePages.value = []
  pollLive(true)
})

let liveTimer: ReturnType<typeof setInterval>
let dashTimer: ReturnType<typeof setInterval>

function onVisible() {
  if (document.hidden) return
  // catch up after time away: fresh live numbers + a quiet data refresh
  pollLive()
  load(true)
}

onMounted(async () => {
  document.addEventListener('visibilitychange', onVisible)
  ;[sites.value, me.value] = await Promise.all([api<Site[]>('/sites'), api<Me>('/auth/me')])
  await load()
  await pollLive(true)
  liveTimer = setInterval(pollLive, 15_000)
  // Rollups land once a minute (cron), so a faster refresh can't show newer
  // data — 60s, visible tabs only, updating in place with no spinner.
  dashTimer = setInterval(() => {
    if (!document.hidden) load(true)
  }, 60_000)
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
// One-click self-update (admin, release installs). The API swaps the code in
// place and migrates; a full reload picks up the new dashboard build.
const updateState = ref<'idle' | 'running' | 'failed'>('idle')
async function runUpdate() {
  updateState.value = 'running'
  try {
    const r = await api<{ version: string }>('/update/run', { method: 'POST' })
    // Survives the reload so the fresh page can confirm the update worked.
    sessionStorage.setItem('melytics_updated', r.version)
    location.reload()
  } catch {
    updateState.value = 'failed'
  }
}

// Post-reload confirmation: shows once, then fades itself out.
const updatedTo = ref(sessionStorage.getItem('melytics_updated'))
if (updatedTo.value) {
  sessionStorage.removeItem('melytics_updated')
  setTimeout(() => (updatedTo.value = null), 5000)
}

// Minimize, never dismiss: the reminder collapses to a pill that restores it,
// so it can't be lost by a stray click; it only disappears when cron runs.
const cronBannerCollapsed = ref(localStorage.getItem('melytics_cron_banner') === 'min')
function setCronBanner(min: boolean) {
  cronBannerCollapsed.value = min
  localStorage.setItem('melytics_cron_banner', min ? 'min' : '')
}

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
onBeforeUnmount(() => {
  clearInterval(liveTimer)
  clearInterval(dashTimer)
  document.removeEventListener('visibilitychange', onVisible)
})

// explicit closure: the watcher's (new, old) args must not land in load(silent)
watch([siteId, rangeDays, filter, customRange], () => load())

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
  try {
    const updated = await api<Site>(`/sites/${site.value.id}`, {
      method: 'PATCH',
      body: JSON.stringify({ tier2_enabled: on }),
    })
    sites.value = sites.value.map((s) => (s.id === updated.id ? updated : s))
  } catch (e) {
    alert(e instanceof Error ? e.message : 'Could not save')
    return
  }
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
    <!-- Three groups: identity/context left, range centered, config right.
         Sticky: frosted low-alpha bg + blur (de-box, no border), bleeds over the
         container padding so content never peeks at the edges. -->
    <header class="sticky-header sticky top-0 z-20 -mx-5 -mt-6 mb-8 grid items-center gap-x-3 gap-y-2 px-5 pt-6 pb-3 md:grid-cols-[1fr_auto_1fr]">
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
        <ModulesPanel
          :modules="orderedModules"
          :hidden="hidden"
          :tier2="site?.tier2_enabled ?? false"
          @toggle="toggleModule"
          @tier2="setTier2"
          @reorder="moveKey"
        />
        <SettingsPanel
          :site="site ?? null"
          @add-site="addSite"
          @delete-site="deleteSite"
        />
        <AccountPanel
          :site="site ?? null"
          :me="me"
          :density="density"
          @notify="setNotify"
          @density="setDensity"
          @signout="logout"
        />
      </div>
    </header>

    <SetupWizard v-if="siteId" ref="wizard" :site-id="siteId" :has-goals="goals.length > 0" :targets="targets" @created="() => load()" />

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
      v-if="me?.cron_stale && !cronBannerCollapsed"
      class="mb-6 flex items-start gap-3 rounded-[14px] bg-[var(--accent-soft)] px-4 py-3 text-sm"
    >
      <div class="min-w-0 flex-1">
        <p>
          Stats update only when you open the dashboard — the every-minute cron job isn't running.
          Add it in your hosting panel for always-fresh stats and email reports:
          <code class="rounded bg-[var(--bg)] px-1.5 py-0.5 text-xs select-all break-all">{{ me.cron_line }}</code>
        </p>
        <p class="mt-1.5 text-xs text-[var(--ink-3)]">
          Already added it? Some hosts take up to ~30 minutes before a new cron job first runs — this banner clears itself once it does.
        </p>
      </div>
      <button
        class="-mr-1 -mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-[var(--ink-3)] hover:bg-[var(--bg)] hover:text-[var(--ink)]"
        title="Minimize"
        @click="setCronBanner(true)"
      >
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 12h14" /></svg>
      </button>
    </div>

    <button
      v-if="me?.cron_stale && cronBannerCollapsed"
      class="mb-6 flex items-center gap-2 rounded-full bg-[var(--accent-soft)] px-3 py-1.5 text-xs text-[var(--ink-2)] hover:text-[var(--ink)]"
      title="Show cron setup reminder"
      @click="setCronBanner(false)"
    >
      <span class="h-1.5 w-1.5 rounded-full bg-[var(--accent)]"></span>
      Waiting for the cron job's first run…
    </button>

    <Transition name="fade">
      <div
        v-if="updatedTo"
        class="mb-6 flex items-center gap-2.5 rounded-[14px] bg-[var(--accent-soft)] px-4 py-3 text-sm"
      >
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
        <span>Update successful — you're on <b>v{{ updatedTo }}</b>.</span>
      </div>
    </Transition>

    <div
      v-if="me?.update"
      class="mb-6 flex flex-wrap items-center gap-3 rounded-[14px] bg-[var(--accent-soft)] px-4 py-3 text-sm"
    >
      <span>
        melytics <b>v{{ me.update.latest }}</b> is out — you're on v{{ me.version }}.
        <a :href="me.update.url" target="_blank" rel="noopener" class="font-medium text-[var(--accent)] hover:opacity-80">What's new</a>
      </span>
      <template v-if="me.is_admin">
        <button
          v-if="updateState !== 'running'"
          class="rounded-lg bg-[var(--accent)] px-3 py-1.5 text-xs font-medium text-white hover:opacity-90"
          @click="runUpdate"
        >
          {{ updateState === 'failed' ? 'Retry update' : 'Update now' }}
        </button>
        <span v-else class="text-[var(--ink-3)]">Updating — this takes a minute, keep this tab open…</span>
        <span v-if="updateState === 'failed'" class="text-[var(--ink-3)]">Update failed — try again, or update manually from GitHub.</span>
      </template>
    </div>

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
      <StatStrip :stats="stats" :metric="metric" :live="live" :line-style="chartStyle" :site-id="siteId" @update:metric="metric = $event" />

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

        <TimeChart :key="`${theme}-${accent}-${accentHex}`" :series="stats.series" :previous="stats.previous_series" :metric="metric" :annotations="annotations" :line-style="chartStyle" />

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
        <template v-for="k in rowOrder" :key="k">
          <div
            v-if="show(k)"
            draggable="true"
            class="rounded-[14px] transition-opacity"
            :class="{ 'opacity-40': dragKey === k, 'ring-2 ring-[var(--accent)]': overKey === k && dragKey && dragKey !== k }"
            @dragstart="dragKey = k"
            @dragend=";(dragKey = null), (overKey = null)"
            @dragover.prevent="overKey = k"
            @dragleave="overKey === k && (overKey = null)"
            @drop.prevent="dropOn(k)"
          >
            <GoalsCard v-if="k === 'goals'" class="h-full" :site-id="siteId" :goals="goals" :targets="targets" @changed="() => load()" @assist="wizard?.show(goals.length ? 1 : 0)" />
            <FunnelsCard v-else class="h-full" :site-id="siteId" :funnels="funnels" :targets="targets" @changed="() => load()" @assist="wizard?.show(2)" />
          </div>
        </template>
      </div>

      <TransitionGroup tag="div" name="mods" class="relative grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
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
          <BotsCard v-else-if="p.key === 'bots' && bots" class="h-full" :bots="bots" :humans="stats?.totals.pageviews ?? 0" />
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
            v-else-if="!['vitals', 'bots', 'retention', 'live', 'cohorts', 'loyalty', 'attribution', 'ttc'].includes(p.key)"
            class="h-full"
            :title="p.title"
            :rows="breakdowns[p.key] ?? []"
            :dim="p.key"
            :clickable="!p.inert"
            :selected="filter?.dim === p.key ? filter.value : null"
            @select="(v) => !p.inert && setFilter(p.key, v)"
          />
        </div>
      </TransitionGroup>
    </main>

    <p v-else-if="loading" class="text-[var(--ink-3)]">Loading…</p>

    <AppFooter />
  </div>
</template>

<style scoped>
/* Frosted sticky header — low-alpha bg token + blur so both modes come free */
.sticky-header {
  background: color-mix(in srgb, var(--bg) 72%, transparent);
  backdrop-filter: blur(14px) saturate(1.4);
  -webkit-backdrop-filter: blur(14px) saturate(1.4);
}

/* FLIP move when grid cards reflow after a toggle or reorder; entering/leaving
   cards fade instead of popping (leave goes absolute so neighbors glide at once) */
.mods-move,
.mods-enter-active,
.mods-leave-active {
  transition: transform 0.25s cubic-bezier(0.23, 1, 0.32, 1), opacity 0.2s ease;
}
.mods-enter-from,
.mods-leave-to {
  opacity: 0;
  transform: scale(0.95);
}
.mods-leave-active {
  position: absolute;
}
@media (prefers-reduced-motion: reduce) {
  .mods-move,
  .mods-enter-active,
  .mods-leave-active {
    transition: opacity 0.2s ease;
    transform: none;
  }
}

.fade-leave-active {
  transition: opacity 0.4s ease, transform 0.4s ease;
}
.fade-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
