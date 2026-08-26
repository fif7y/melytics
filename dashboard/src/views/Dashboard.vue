<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, setToken, type Annotation, type BreakdownRow, type Site, type Stats } from '../lib/api'
import TimeChart from '../components/TimeChart.vue'
import BreakdownCard from '../components/BreakdownCard.vue'
import GoalsCard, { type GoalRow } from '../components/GoalsCard.vue'
import FunnelsCard, { type FunnelRow } from '../components/FunnelsCard.vue'
import SharePanel from '../components/SharePanel.vue'

const route = useRoute()
const router = useRouter()

const sites = ref<Site[]>([])
const stats = ref<Stats | null>(null)
const goals = ref<GoalRow[]>([])
const funnels = ref<FunnelRow[]>([])
const annotations = ref<Annotation[]>([])
const noting = ref(false)
const noteDay = ref(new Date().toISOString().slice(0, 10))
const noteText = ref('')
const breakdowns = ref<Record<string, BreakdownRow[]>>({})
const live = ref<number | null>(null)
const metric = ref<'visitors' | 'pageviews'>('visitors')
const rangeDays = ref(30)
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

async function load() {
  if (!siteId.value) return
  loading.value = true
  const id = siteId.value
  const [s, g, f, a, ...panels] = await Promise.all([
    api<Stats>(`/sites/${id}/stats?${rangeParams()}`),
    api<{ goals: GoalRow[] }>(`/sites/${id}/goals?${rangeParams()}`),
    api<{ funnels: FunnelRow[] }>(`/sites/${id}/funnels?${rangeParams()}`),
    api<{ annotations: Annotation[] }>(`/sites/${id}/annotations?${rangeParams()}`),
    ...PANELS.map((p) =>
      api<{ rows: BreakdownRow[] }>(`/sites/${id}/breakdown?dimension=${p.key}&${rangeParams()}&limit=8`)
    ),
  ])
  stats.value = s as Stats
  goals.value = (g as { goals: GoalRow[] }).goals
  funnels.value = (f as { funnels: FunnelRow[] }).funnels
  annotations.value = (a as { annotations: Annotation[] }).annotations
  breakdowns.value = Object.fromEntries(PANELS.map((p, i) => [p.key, panels[i].rows]))
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

watch([siteId, rangeDays], load)

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
    <header class="flex items-center gap-4 mb-8">
      <h1 class="text-lg font-semibold tracking-tight">melytics</h1>

      <select
        v-if="sites.length > 1"
        :value="siteId"
        class="rounded-lg bg-[var(--surface)] px-3 py-1.5 text-sm outline-none"
        @change="router.push(`/${($event.target as HTMLSelectElement).value}`)"
      >
        <option v-for="s in sites" :key="s.id" :value="s.id">{{ s.domain }}</option>
      </select>
      <span v-else-if="site" class="text-sm text-[var(--ink-2)]">{{ site.domain }}</span>

      <span v-if="live !== null" class="flex items-center gap-1.5 text-sm text-[var(--ink-2)]">
        <span class="h-2 w-2 rounded-full bg-[var(--up)]" :class="{ 'animate-pulse': live > 0 }" />
        {{ live }} online
      </span>

      <div class="ml-auto flex items-center gap-1 rounded-lg bg-[var(--surface)] p-1">
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
      <button class="text-sm text-[var(--ink-3)] hover:text-[var(--ink)]" @click="logout">Sign out</button>
    </header>

    <main v-if="stats" class="space-y-5">
      <section class="card p-5">
        <div class="flex items-baseline gap-6 mb-2">
          <button
            v-for="m in ['visitors', 'pageviews'] as const"
            :key="m"
            class="text-left"
            :class="metric === m ? '' : 'opacity-45'"
            @click="metric = m"
          >
            <div class="text-sm text-[var(--ink-2)] capitalize">{{ m }}</div>
            <div class="text-3xl font-semibold tabular-nums tracking-tight">
              {{ stats.totals[m].toLocaleString() }}
            </div>
          </button>
          <span
            v-if="delta !== null"
            class="text-sm tabular-nums"
            :style="{ color: delta >= 0 ? 'var(--up)' : 'var(--down)' }"
          >
            {{ delta >= 0 ? '↑' : '↓' }} {{ Math.abs(delta) }}% vs previous {{ rangeDays }}d
          </span>
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

      <div class="grid gap-5 lg:grid-cols-2">
        <GoalsCard :site-id="siteId" :goals="goals" @changed="load" />
        <FunnelsCard :site-id="siteId" :funnels="funnels" @changed="load" />
      </div>

      <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        <BreakdownCard
          v-for="p in PANELS"
          :key="p.key"
          :title="p.title"
          :rows="breakdowns[p.key] ?? []"
        />
      </div>
    </main>

    <p v-else-if="loading" class="text-[var(--ink-3)]">Loading…</p>
  </div>
</template>
