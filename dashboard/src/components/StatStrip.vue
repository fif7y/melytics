<script setup lang="ts">
import { computed, ref } from 'vue'
import type { Stats } from '../lib/api'
import { useSiteScopedRef, safeJson } from '../lib/persist'

const props = defineProps<{
  stats: Stats
  metric: 'visitors' | 'pageviews'
  live: number | null
  lineStyle?: 'linear' | 'smooth' | 'step' | 'bars' | 'glow'
  siteId?: number
}>()
const emit = defineEmits<{ 'update:metric': [m: 'visitors' | 'pageviews'] }>()

// Sparkline geometry follows the chart's line style so the dashboard rounds together
function sparkPath(values: number[], w = 120, h = 28) {
  const style = props.lineStyle ?? 'smooth'
  const none = { line: '', area: '', end: null as { x: number; y: number } | null, bars: [] as { x: number; y: number; w: number; h: number }[] }
  if (values.length < 2) return none
  const max = Math.max(...values)
  const min = Math.min(...values)
  const x = (i: number) => (i / (values.length - 1)) * w
  const y = (v: number) => h - 2.5 - ((v - min) / (max - min || 1)) * (h - 6)
  const p = values.map((v, i) => [x(i), y(v)] as [number, number])

  if (style === 'bars') {
    const bw = (w / values.length) * 0.62
    return { ...none, bars: values.map((v, i) => ({ x: x(i) - bw / 2, y: y(v), w: bw, h: h - y(v) })) }
  }

  let line: string
  if (style === 'linear') {
    line = p.map(([px, py], i) => `${i ? 'L' : 'M'}${px.toFixed(1)},${py.toFixed(1)}`).join('')
  } else if (style === 'step') {
    line = `M${p[0][0].toFixed(1)},${p[0][1].toFixed(1)}` + p.slice(1).map(([px, py]) => `H${px.toFixed(1)}V${py.toFixed(1)}`).join('')
  } else {
    // Catmull-Rom spline, same curve as the big chart
    line = `M${p[0][0].toFixed(1)},${p[0][1].toFixed(1)}`
    for (let i = 0; i < p.length - 1; i++) {
      const p0 = p[Math.max(i - 1, 0)], p1 = p[i], p2 = p[i + 1], p3 = p[Math.min(i + 2, p.length - 1)]
      const c1 = [p1[0] + (p2[0] - p0[0]) / 6, p1[1] + (p2[1] - p0[1]) / 6]
      const c2 = [p2[0] - (p3[0] - p1[0]) / 6, p2[1] - (p3[1] - p1[1]) / 6]
      line += `C${c1[0].toFixed(1)},${c1[1].toFixed(1)} ${c2[0].toFixed(1)},${c2[1].toFixed(1)} ${p2[0].toFixed(1)},${p2[1].toFixed(1)}`
    }
  }
  return {
    ...none,
    line,
    area: `${line}L${w},${h}L0,${h}Z`,
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
        key: 'duration',
        label: 'Avg. visit',
        value: s.totals.avg_duration != null ? mmss(s.totals.avg_duration) : '—',
        delta: delta(s.totals.avg_duration ?? 0, s.previous_totals.avg_duration ?? 0),
        spark: s.series.map((p) => (p.sessions ? (p.duration_sum ?? 0) / p.sessions : 0)),
      },
      {
        key: 'bounce',
        label: 'Bounce rate',
        value: s.totals.bounce_rate != null ? `${s.totals.bounce_rate}%` : '—',
        delta: delta(s.totals.bounce_rate ?? 0, s.previous_totals.bounce_rate ?? 0),
        invert: true, // a falling bounce rate is the good direction
        spark: s.series.map((p) => (p.sessions ? (p.bounces ?? 0) / p.sessions : 0)),
      }
    )
  }
  if (props.live !== null) {
    out.push({ key: 'live', label: 'Live now', value: String(props.live), delta: null, liveDot: true })
  }
  return out
})

// Drag-to-reorder, persisted per-site like the breakdown grid
const order = useSiteScopedRef<string[]>(
  'melytics_stat_order',
  computed(() => props.siteId),
  (raw) => (safeJson(raw) as string[]) ?? [],
  true
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
        <template v-if="sparkPath(t.spark).bars.length">
          <rect v-for="(b, bi) in sparkPath(t.spark).bars" :key="bi" :x="b.x" :y="b.y" :width="b.w" :height="b.h" rx="1" fill="var(--accent)" opacity="0.85" />
        </template>
        <template v-else>
          <path :d="sparkPath(t.spark).area" fill="var(--accent-soft)" />
          <path :d="sparkPath(t.spark).line" fill="none" stroke="var(--accent)" stroke-width="1.5" stroke-linecap="round" vector-effect="non-scaling-stroke" />
          <circle v-if="sparkPath(t.spark).end" :cx="sparkPath(t.spark).end!.x" :cy="sparkPath(t.spark).end!.y" r="2.2" fill="var(--accent)" />
        </template>
      </svg>
    </component>
  </div>
</template>
