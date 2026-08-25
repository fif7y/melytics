<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, setToken, type BreakdownRow, type Site, type Stats } from '../lib/api'
import TimeChart from '../components/TimeChart.vue'
import BreakdownCard from '../components/BreakdownCard.vue'

const route = useRoute()
const router = useRouter()

const sites = ref<Site[]>([])
const stats = ref<Stats | null>(null)
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
  const [s, ...panels] = await Promise.all([
    api<Stats>(`/sites/${id}/stats?${rangeParams()}`),
    ...PANELS.map((p) =>
      api<{ rows: BreakdownRow[] }>(`/sites/${id}/breakdown?dimension=${p.key}&${rangeParams()}&limit=8`)
    ),
  ])
  stats.value = s
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
        </div>
        <TimeChart :series="stats.series" :previous="stats.previous_series" :metric="metric" />
      </section>

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
