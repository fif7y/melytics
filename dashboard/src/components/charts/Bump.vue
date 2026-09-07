<script setup lang="ts">
import { computed, ref } from 'vue'
import { useChartTip } from '../../lib/useChartTip'

/**
 * Rank of each top value per week (daily buckets summed by 7; hourly ranges
 * rank per day). The current leader is in accent, the rest grey; hovering
 * lifts one line. Only rank is shown — the stacked layout carries magnitude.
 */
const props = defineProps<{ buckets: string[]; series: { value: string; points: number[] }[] }>()
const { tip, show, hide, tipStyle } = useChartTip()
const hl = ref<string | null>(null)
const W = 400, LEFT = 96
const data = computed(() => {
  const hourly = (props.buckets[0]?.length ?? 0) > 10
  // columns: hours→days, short daily ranges→days, longer→weeks
  const per = hourly ? 24 : props.buckets.length < 21 ? 1 : 7
  const daily = per !== 7
  const cols = Math.max(2, Math.ceil(props.buckets.length / per))
  const sums = props.series.map((s) => Array.from({ length: cols }, (_, c) => s.points.slice(c * per, (c + 1) * per).reduce((a, b) => a + b, 0)))
  const ranks = props.series.map(() => new Array<number>(cols).fill(0))
  for (let c = 0; c < cols; c++) {
    const order = sums.map((s, i) => [s[c], i] as const).sort((a, b) => b[0] - a[0])
    order.forEach(([, i], r) => (ranks[i][c] = r + 1))
  }
  const H = Math.max(120, props.series.length * 26)
  const x = (c: number) => LEFT + (c / (cols - 1)) * (W - LEFT - 8)
  const y = (r: number) => 10 + ((r - 1) / Math.max(1, props.series.length - 1)) * (H - 20)
  const lines = props.series.map((s, i) => ({
    value: s.value,
    d: ranks[i].map((r, c) => `${c ? 'L' : 'M'}${x(c).toFixed(1)} ${y(r).toFixed(1)}`).join(' '),
    first: { x: x(0), y: y(ranks[i][0]) },
    last: { x: x(cols - 1), y: y(ranks[i][cols - 1]) },
    from: ranks[i][0],
    to: ranks[i][cols - 1],
    lead: ranks[i][cols - 1] === 1,
  }))
  const labels = lines.map((l) => ({ ...l, ly: l.first.y })).sort((a, b) => a.ly - b.ly)
  for (let i = 1; i < labels.length; i++) if (labels[i].ly - labels[i - 1].ly < 12) labels[i].ly = labels[i - 1].ly + 12
  const ticks = Array.from({ length: cols }, (_, c) => ({
    x: x(c),
    label: c === cols - 1 ? (daily ? 'today' : 'this wk') : daily ? `d${c - cols + 1}` : `w${c - cols + 1}`,
  }))
  return { H, lines, labels, ticks }
})
const color = (l: { value: string; lead: boolean }) => (l.lead || hl.value === l.value ? 'var(--accent)' : 'var(--compare)')
</script>

<template>
  <div>
    <svg class="chart" :viewBox="`0 0 ${W} ${data.H + 16}`" @mouseleave="hl = null">
      <g
        v-for="l in data.lines"
        :key="l.value"
        class="cursor-default"
        :opacity="hl && hl !== l.value ? 0.35 : 1"
        @mousemove="(hl = l.value), show($event, `${l.value} · #${l.from} → #${l.to}`)"
        @mouseleave="hide"
      >
        <path :d="l.d" fill="none" :stroke="color(l)" :stroke-width="l.lead || hl === l.value ? 2.5 : 1.5" stroke-linejoin="round" stroke-linecap="round" />
        <circle :cx="l.first.x" :cy="l.first.y" r="3.5" :fill="color(l)" stroke="var(--surface)" stroke-width="2" />
        <circle :cx="l.last.x" :cy="l.last.y" r="3.5" :fill="color(l)" stroke="var(--surface)" stroke-width="2" />
      </g>
      <text v-for="l in data.labels" :key="l.value" :x="LEFT - 10" :y="l.ly + 4" text-anchor="end" :style="{ fill: l.lead ? 'var(--ink)' : 'var(--ink-3)', fontWeight: l.lead ? 500 : 400 }">{{ l.value.length > 13 ? l.value.slice(0, 12) + '…' : l.value }}</text>
      <text v-for="t in data.ticks" :key="t.x" :x="t.x" :y="data.H + 12" text-anchor="middle">{{ t.label }}</text>
    </svg>
    <div v-if="tip" class="chart-tip" :style="tipStyle()">{{ tip.text }}</div>
  </div>
</template>
