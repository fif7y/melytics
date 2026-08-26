<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import type { BreakdownRow, Stats } from '../lib/api'
import TimeChart from '../components/TimeChart.vue'
import BreakdownCard from '../components/BreakdownCard.vue'

const route = useRoute()
const token = computed(() => String(route.params.token))

const base = (window as { MELYTICS_API?: string }).MELYTICS_API ?? '/api'

const meta = ref<{ site: { name: string; domain: string }; requires_password: boolean } | null>(null)
const stats = ref<(Stats & { site: { domain: string } }) | null>(null)
const breakdowns = ref<Record<string, BreakdownRow[]>>({})
const metric = ref<'visitors' | 'pageviews'>('visitors')
const rangeDays = ref(30)
const password = ref('')
const error = ref('')
const notFound = ref(false)

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
]

function auth(): string {
  try {
    return sessionStorage.getItem(`melytics_share_${token.value}`) ?? ''
  } catch {
    return ''
  }
}

function rangeParams() {
  const iso = (d: Date) => d.toISOString().slice(0, 10)
  const from = new Date(Date.now() - (rangeDays.value - 1) * 86400_000)
  return `from=${iso(from)}&to=${iso(new Date())}&auth=${auth()}`
}

async function get<T>(path: string): Promise<T> {
  const res = await fetch(`${base}/share/${token.value}${path}`, { headers: { Accept: 'application/json' } })
  if (!res.ok) throw res.status
  return res.json()
}

async function load() {
  try {
    stats.value = await get(`/stats?${rangeParams()}`)
    const panels = await Promise.all(
      PANELS.map((p) => get<{ rows: BreakdownRow[] }>(`/breakdown?dimension=${p.key}&${rangeParams()}&limit=8`))
    )
    breakdowns.value = Object.fromEntries(PANELS.map((p, i) => [p.key, panels[i].rows]))
  } catch (status) {
    if (status !== 401) notFound.value = true
  }
}

async function unlock() {
  error.value = ''
  try {
    const res = await fetch(`${base}/share/${token.value}/unlock`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ password: password.value }),
    })
    if (!res.ok) throw 0
    const { auth: a } = await res.json()
    try {
      sessionStorage.setItem(`melytics_share_${token.value}`, a)
    } catch {}
    meta.value!.requires_password = false
    await load()
  } catch {
    error.value = 'Wrong password'
  }
}

onMounted(async () => {
  try {
    meta.value = await get('/')
  } catch {
    notFound.value = true
    return
  }
  if (!meta.value!.requires_password || auth()) await load()
})

watch(rangeDays, load)
</script>

<template>
  <div class="mx-auto max-w-6xl px-5 py-6">
    <p v-if="notFound" class="text-[var(--ink-3)] text-center mt-24">This share link doesn't exist or is disabled.</p>

    <template v-else-if="meta">
      <header class="flex items-center gap-4 mb-8">
        <h1 class="text-lg font-semibold tracking-tight">{{ meta.site.domain }}</h1>
        <span class="text-sm text-[var(--ink-3)]">shared stats</span>
        <div v-if="stats" class="ml-auto flex items-center gap-1 rounded-lg bg-[var(--surface)] p-1">
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
      </header>

      <form
        v-if="meta.requires_password && !stats"
        class="card max-w-sm mx-auto mt-24 p-6 space-y-4"
        @submit.prevent="unlock"
      >
        <p class="text-sm text-[var(--ink-2)]">This dashboard is password-protected.</p>
        <input
          v-model="password"
          type="password"
          placeholder="Password"
          class="w-full rounded-lg px-3.5 py-2.5 bg-[var(--bg)] outline-none focus:ring-2 ring-[var(--accent)] placeholder:text-[var(--ink-3)]"
        />
        <button class="w-full rounded-lg py-2.5 font-medium text-white bg-[var(--accent)]">View stats</button>
        <p v-if="error" class="text-sm text-[var(--down)] text-center">{{ error }}</p>
      </form>

      <main v-else-if="stats" class="space-y-5">
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
          </div>
          <TimeChart :series="stats.series" :previous="stats.previous_series" :metric="metric" />
        </section>

        <div class="grid gap-5 sm:grid-cols-2">
          <BreakdownCard v-for="p in PANELS" :key="p.key" :title="p.title" :rows="breakdowns[p.key] ?? []" :dim="p.key" />
        </div>

        <p class="text-xs text-[var(--ink-3)] text-center pt-4">measured by melytics</p>
      </main>
    </template>
  </div>
</template>
