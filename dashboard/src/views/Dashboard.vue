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
import { theme, toggleTheme, effectiveTheme } from '../lib/theme'
import SettingsPanel from '../components/SettingsPanel.vue'
import AccountPanel from '../components/AccountPanel.vue'

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
const RANGE_KEY = 'melytics_range'
const rangeDays = ref(
  (() => {
    const n = Number(localStorage.getItem(RANGE_KEY))
    return [1, 7, 30, 90].includes(n) ? n : 30
  })()
)
watch(rangeDays, (d) => {
  try {
    localStorage.setItem(RANGE_KEY, String(d))
  } catch {}
})
const filter = ref<{ dim: string; value: string } | null>(null)
const loading = ref(true)

const RANGES = [
  { label: 'Today', days: 1 },
  { label: '7d', days: 7 },
  { label: '30d', days: 30 },
  { label: '90d', days: 90 },
]
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
// Tier-2 modules only render (and fetch) when the site has tier-2 tracking on
const TIER2_KEYS = MODULES.filter((m) => 'tier2' in m && m.tier2).map((m) => m.key)
const visible = (key: string) => show(key) && (!TIER2_KEYS.includes(key) || (site.value?.tier2_enabled ?? false))

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
// Default grid order for fresh installs — the curated layout (saved 2026-08-26)
const GRID_DEFAULT = ['page', 'live', 'country', 'referrer', 'device', 'browser', 'vitals', 'entry_page', 'exit_page', 'channel', 'not_found', 'retention', 'outbound', 'download', 'utm_source', 'utm_medium', 'cohorts', 'loyalty', 'attribution', 'ttc', 'utm_campaign', 'event']
const SPECIAL_TITLES: Record<string, string> = { live: 'Live', vitals: 'Web Vitals', retention: 'Retention', cohorts: 'Cohorts', loyalty: 'Loyalty', attribution: 'Attribution', ttc: 'Time to convert' }
const GRID_ITEMS = GRID_DEFAULT.map((k) => PANELS.find((p) => p.key === k) ?? { key: k, title: SPECIAL_TITLES[k] })
const orderedPanels = computed(() => {
  const idx = (k: string) => {
    const i = order.value.indexOf(k)
    return i === -1 ? 100 + GRID_ITEMS.findIndex((p) => p.key === k) : i
  }
  return GRID_ITEMS.filter((p) => visible(p.key)).slice().sort((a, b) => idx(a.key) - idx(b.key))
})
const dragKey = ref<string | null>(null)
const overKey = ref<string | null>(null)

// Settings lists modules in on-screen order: goals/funnels row first, then the grid's order
const orderedModules = computed(() => {
  const idx = (k: string) => {
    if (k === 'goals') return -2
    if (k === 'funnels') return -1
    const i = order.value.indexOf(k)
    return i === -1 ? 100 + GRID_ITEMS.findIndex((p) => p.key === k) : i
  }
  return MODULES.slice().sort((a, b) => idx(a.key) - idx(b.key))
})
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
  // local calendar date, not toISOString (which is UTC and flips the day in the evening)
  const iso = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
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
  if (!siteId.value) {
    loading.value = false
    return
  }
  loading.value = true
  const id = siteId.value
  // hidden modules are not fetched at all (funnels especially are heavier queries)
  const activePanels = PANELS.filter((p) => show(p.key))
  const [s, a, g, f, v, rt, co, lo, at, tc, ...panels] = await Promise.all([
    api<Stats>(`/sites/${id}/stats?${rangeParams()}${filterQS()}`),
    api<{ annotations: Annotation[] }>(`/sites/${id}/annotations?${rangeParams()}`),
    show('goals') ? api<{ goals: GoalRow[] }>(`/sites/${id}/goals?${rangeParams()}`) : null,
    show('funnels') ? api<{ funnels: FunnelRow[] }>(`/sites/${id}/funnels?${rangeParams()}`) : null,
    show('vitals') ? api<Vitals>(`/sites/${id}/vitals?${rangeParams()}`) : null,
    visible('retention') ? api<Retention>(`/sites/${id}/retention?${rangeParams()}`) : null,
    visible('cohorts') ? api<{ cohorts: CohortRow[] }>(`/sites/${id}/cohorts`) : null,
    visible('loyalty') ? api<Loyalty>(`/sites/${id}/loyalty?${rangeParams()}`) : null,
    visible('attribution') ? api<Attribution>(`/sites/${id}/attribution?${rangeParams()}`) : null,
    visible('ttc') ? api<TimeToConvert>(`/sites/${id}/time-to-convert?${rangeParams()}`) : null,
    ...activePanels.map((p) =>
      api<{ rows: BreakdownRow[] }>(`/sites/${id}/breakdown?dimension=${p.key}&${rangeParams()}&limit=8${filterQS()}`)
    ),
  ])
  stats.value = s as Stats
  annotations.value = (a as { annotations: Annotation[] }).annotations
  goals.value = g ? (g as { goals: GoalRow[] }).goals : []
  funnels.value = f ? (f as { funnels: FunnelRow[] }).funnels : []
  vitals.value = v ? (v as Vitals) : null
  retention.value = rt ? (rt as Retention) : null
  cohorts.value = co ? (co as { cohorts: CohortRow[] }).cohorts : null
  loyalty.value = lo ? (lo as Loyalty) : null
  attribution.value = at ? (at as Attribution) : null
  ttc.value = tc ? (tc as TimeToConvert) : null
  breakdowns.value = Object.fromEntries(activePanels.map((p, i) => [p.key, panels[i].rows]))
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

        <button
          class="flex h-9 w-9 items-center justify-center rounded-lg text-[var(--ink-2)] hover:bg-[var(--surface)] hover:text-[var(--ink)]"
          :title="effectiveTheme() === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
          :aria-label="effectiveTheme() === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
          @click="toggleTheme()"
        >
          <svg v-if="effectiveTheme() === 'dark'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="4" />
            <path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
          </svg>
          <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
          </svg>
        </button>

        <SharePanel v-if="siteId" :key="siteId" :site-id="siteId" />
        <SettingsPanel
          :modules="orderedModules"
          :hidden="hidden"
          :density="density"
          :tier2="site?.tier2_enabled ?? false"
          @toggle="toggleModule"
          @density="setDensity"
          @tier2="setTier2"
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
      <StatStrip :stats="stats" :metric="metric" :live="live" @update:metric="metric = $event" />

      <section class="card p-5">
        <div class="flex items-baseline gap-3 mb-2">
          <span class="text-sm text-[var(--ink-2)] capitalize">{{ metric }}</span>
          <span v-if="delta !== null" class="text-xs tabular-nums text-[var(--ink-3)]">vs {{ rangeDays === 1 ? 'yesterday' : `previous ${rangeDays}d` }}</span>
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

        <TimeChart :key="theme" :series="stats.series" :previous="stats.previous_series" :metric="metric" :annotations="annotations" />

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
        <GoalsCard v-if="show('goals')" class="h-full" :site-id="siteId" :goals="goals" @changed="load" />
        <FunnelsCard v-if="show('funnels')" class="h-full" :site-id="siteId" :funnels="funnels" @changed="load" />
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
