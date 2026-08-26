<script setup lang="ts">
import { computed, ref } from 'vue'
import type { Stats } from '../lib/api'

const props = defineProps<{
  stats: Stats
  metric: 'visitors' | 'pageviews'
  live: number | null
}>()
const emit = defineEmits<{ 'update:metric': [m: 'visitors' | 'pageviews'] }>()

function sparkPath(values: number[], w = 120, h = 28) {
  if (values.length < 2) return { line: '', area: '', end: null as { x: number; y: number } | null }
  const max = Math.max(...values)
  const min = Math.min(...values)
  const x = (i: number) => (i / (values.length - 1)) * w
  const y = (v: number) => h - 2.5 - ((v - min) / (max - min || 1)) * (h - 6)
  const pts = values.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`)
  return {
    line: pts.join(' '),
    area: `0,${h} ${pts.join(' ')} ${w},${h}`,
    end: { x: x(values.length - 1), y: y(values[values.length - 1]) },
  }
}

const delta = (cur: number, prev: number) => (prev ? Math.round(((cur - prev) / prev) * 100) : null)

const tiles = computed(() => {
  const s = props.stats
  const perVisit = (t: { pageviews: number; visitors: number }) => (t.visitors ? t.pageviews / t.visitors : 0)
  const out: {
    key: string
    label: string
    value: string
    delta: number | null
    invert?: boolean
    spark?: number[]
    metric?: 'visitors' | 'pageviews'
    liveDot?: boolean
  }[] = [
    {
      key: 'visitors',
      label: 'Visitors',
      value: s.totals.visitors.toLocaleString(),
      delta: delta(s.totals.visitors, s.previous_totals.visitors),
      spark: s.series.map((p) => p.visitors),
      metric: 'visitors',
    },
    {
      key: 'pageviews',
      label: 'Pageviews',
      value: s.totals.pageviews.toLocaleString(),
      delta: delta(s.totals.pageviews, s.previous_totals.pageviews),
      spark: s.series.map((p) => p.pageviews),
      metric: 'pageviews',
    },
    {
      key: 'per-visit',
      label: 'Views / visit',
      value: perVisit(s.totals) ? perVisit(s.totals).toFixed(1) : '—',
      delta: delta(perVisit(s.totals), perVisit(s.previous_totals)),
      spark: s.series.map((p) => (p.visitors ? p.pageviews / p.visitors : 0)),
    },
  ]
  if (s.totals.sessions !== undefined) {
    const mmss = (secs: number) => `${Math.floor(secs / 60)}:${String(Math.round(secs % 60)).padStart(2, '0')}`
    out.push(
      {
        key: 'bounce',
        label: 'Bounce rate',
        value: s.totals.bounce_rate != null ? `${s.totals.bounce_rate}%` : '—',
        delta: delta(s.totals.bounce_rate ?? 0, s.previous_totals.bounce_rate ?? 0),
        invert: true, // a falling bounce rate is the good direction
        spark: s.series.map((p) => (p.sessions ? (p.bounces ?? 0) / p.sessions : 0)),
      },
      {
        key: 'duration',
        label: 'Avg. visit',
        value: s.totals.avg_duration != null ? mmss(s.totals.avg_duration) : '—',
        delta: delta(s.totals.avg_duration ?? 0, s.previous_totals.avg_duration ?? 0),
        spark: s.series.map((p) => (p.sessions ? (p.duration_sum ?? 0) / p.sessions : 0)),
      }
    )
  }
  if (props.live !== null) {
    out.push({ key: 'live', label: 'Live now', value: String(props.live), delta: null, liveDot: true })
  }
  return out
})

// Drag-to-reorder, persisted like the breakdown grid
const ORDER_KEY = 'melytics_stat_order'
const order = ref<string[]>(
  (() => {
    try {
      return JSON.parse(localStorage.getItem(ORDER_KEY) ?? '[]')
    } catch {
      return []
    }
  })()
)
const dragKey = ref<string | null>(null)
const overKey = ref<string | null>(null)

const orderedTiles = computed(() => {
  const idx = (k: string) => {
    const i = order.value.indexOf(k)
    return i === -1 ? 100 + tiles.value.findIndex((t) => t.key === k) : i
  }
  return tiles.value.slice().sort((a, b) => idx(a.key) - idx(b.key))
})

function dropOn(target: string) {
  if (!dragKey.value || dragKey.value === target) return
  const keys = orderedTiles.value.map((t) => t.key)
  keys.splice(keys.indexOf(target), 0, ...keys.splice(keys.indexOf(dragKey.value), 1))
  order.value = keys
  try {
    localStorage.setItem(ORDER_KEY, JSON.stringify(keys))
  } catch {}
  dragKey.value = null
  overKey.value = null
}
</script>

<template>
  <div class="grid gap-4" :style="{ gridTemplateColumns: `repeat(auto-fit, minmax(9rem, 1fr))` }">
    <component
      :is="t.metric ? 'button' : 'div'"
      v-for="t in orderedTiles"
      :key="t.key"
      draggable="true"
      class="card px-4 pt-3.5 text-left transition-opacity"
      :class="[
        t.metric ? 'cursor-pointer' : '',
        t.spark ? 'pb-2' : 'pb-3.5',
        t.metric && metric !== t.metric ? 'opacity-55 hover:opacity-80' : '',
        { 'opacity-40': dragKey === t.key, 'ring-2 ring-[var(--accent)]': overKey === t.key && dragKey && dragKey !== t.key },
      ]"
      @click="t.metric && emit('update:metric', t.metric)"
      @dragstart="dragKey = t.key"
      @dragend=";(dragKey = null), (overKey = null)"
      @dragover.prevent="overKey = t.key"
      @dragleave="overKey === t.key && (overKey = null)"
      @drop.prevent="dropOn(t.key)"
    >
      <div class="flex items-center gap-1.5 text-xs text-[var(--ink-3)]">
        <span v-if="t.liveDot" class="h-2 w-2 rounded-full bg-[var(--up)]" :class="{ 'animate-pulse': live && live > 0 }" />
        {{ t.label }}
      </div>
      <div class="text-xl font-semibold tabular-nums tracking-tight">
        {{ t.value }}
        <span
          v-if="t.delta !== null && t.delta !== 0"
          class="text-xs font-normal tabular-nums"
          :style="{ color: (t.invert ? t.delta < 0 : t.delta >= 0) ? 'var(--up)' : 'var(--down)' }"
        >
          {{ t.delta >= 0 ? '↑' : '↓' }}{{ Math.abs(t.delta) }}%
        </span>
      </div>
      <svg v-if="t.spark && t.spark.length > 1" viewBox="0 0 120 28" preserveAspectRatio="none" class="mt-1 block h-7 w-full" aria-hidden="true">
        <polygon :points="sparkPath(t.spark).area" fill="var(--accent-soft)" />
        <polyline :points="sparkPath(t.spark).line" fill="none" stroke="var(--accent)" stroke-width="1.5" stroke-linecap="round" vector-effect="non-scaling-stroke" />
        <circle v-if="sparkPath(t.spark).end" :cx="sparkPath(t.spark).end!.x" :cy="sparkPath(t.spark).end!.y" r="2.2" fill="var(--accent)" />
      </svg>
    </component>
  </div>
</template>
