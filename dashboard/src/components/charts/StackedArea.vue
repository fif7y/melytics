<script setup lang="ts">
import { computed } from 'vue'
import { useChartTip, tint, pct } from '../../lib/useChartTip'

/** 100% stacked area: share of each top value per bucket, "Other" in grey on top. */
const props = defineProps<{ buckets: string[]; series: { value: string; points: number[] }[]; other: number[] }>()
const { tip, show, hide, tipStyle } = useChartTip()
const W = 400, H = 150, R = 100
const layers = computed(() => {
  const n = props.buckets.length
  if (n < 2) return []
  const all = [...props.series, { value: 'Other', points: props.other }].filter((s) => s.points.some((v) => v > 0))
  const tot = Array.from({ length: n }, (_, i) => all.reduce((s, l) => s + (l.points[i] ?? 0), 0))
  const x = (i: number) => (i / (n - 1)) * (W - R)
  const cum = new Array<number>(n).fill(0)
  const out = all.map((l, k) => {
    let top = '', bot = ''
    for (let i = 0; i < n; i++) {
      const sh = tot[i] ? (l.points[i] ?? 0) / tot[i] : 0
      const y0 = cum[i], y1 = cum[i] + sh
      top += `${i ? 'L' : 'M'}${x(i).toFixed(1)} ${(H - y1 * H + 1).toFixed(1)} `
      bot = `L${x(i).toFixed(1)} ${(H - y0 * H - (k ? 1 : 0)).toFixed(1)} ` + bot
      cum[i] = y1
    }
    const lastSh = tot[n - 1] ? (l.points[n - 1] ?? 0) / tot[n - 1] : 0
    return { ...l, d: top + bot + 'Z', fill: l.value === 'Other' ? 'var(--compare)' : tint(k), ly: H - (cum[n - 1] - lastSh / 2) * H, share: pct(l.points[n - 1] ?? 0, tot[n - 1]) }
  })
  // end labels read top-down, 12px apart
  out.sort((a, b) => a.ly - b.ly)
  for (let i = 1; i < out.length; i++) if (out[i].ly - out[i - 1].ly < 12) out[i].ly = out[i - 1].ly + 12
  return out
})
const hourly = computed(() => (props.buckets[0]?.length ?? 0) > 10)
const ticks = computed(() => {
  const n = props.buckets.length
  if (n < 2) return []
  const fmt = (b: string) => (hourly.value ? b.slice(11, 16) : b.slice(5).replace('-', '/'))
  return [0, Math.floor((n - 1) / 2), n - 1].map((i) => ({ x: (i / (n - 1)) * (W - R), label: fmt(props.buckets[i]), anchor: i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle' }))
})
</script>

<template>
  <div>
    <svg class="chart" :viewBox="`0 0 ${W} ${H + 18}`">
      <g v-for="l in layers" :key="l.value" @mousemove="show($event, `${l.value} · ${l.share}% in the last ${hourly ? 'hour' : 'day'}`)" @mouseleave="hide">
        <path :d="l.d" :fill="l.fill" />
        <circle :cx="W - R" :cy="l.ly" r="3.5" :fill="l.fill" stroke="var(--surface)" stroke-width="2" />
        <text :x="W - R + 9" :y="l.ly + 4" style="fill:var(--ink)">{{ l.value.length > 12 ? l.value.slice(0, 11) + '…' : l.value }} <tspan fill="var(--ink-3)">{{ l.share }}%</tspan></text>
      </g>
      <text v-for="t in ticks" :key="t.x" :x="t.x" :y="H + 14" :text-anchor="t.anchor">{{ t.label }}</text>
    </svg>
    <div v-if="tip" class="chart-tip" :style="tipStyle()">{{ tip.text }}</div>
  </div>
</template>
