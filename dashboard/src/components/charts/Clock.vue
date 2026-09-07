<script setup lang="ts">
import { computed } from 'vue'
import { useChartTip, fmtInt } from '../../lib/useChartTip'

/** 24 radial bars for today, the range's hourly average as a dotted ring. */
const props = defineProps<{ today: number[]; avg: number[]; nowHour: number }>()
const { tip, show, hide, tipStyle } = useChartTip()
const W = 400, H = 200, CX = 105, CY = 100, R0 = 26, R1 = 84
function arc(cx: number, cy: number, r0: number, r1: number, a0: number, a1: number) {
  const p = (r: number, a: number) => [cx + r * Math.cos(a), cy + r * Math.sin(a)]
  const [x0, y0] = p(r1, a0), [x1, y1] = p(r1, a1), [x2, y2] = p(r0, a1), [x3, y3] = p(r0, a0)
  return `M${x0} ${y0}A${r1} ${r1} 0 0 1 ${x1} ${y1}L${x2} ${y2}A${r0} ${r0} 0 0 0 ${x3} ${y3}Z`
}
const max = computed(() => Math.max(1, ...props.today, ...props.avg))
const bars = computed(() =>
  Array.from({ length: 24 }, (_, h) => {
    const a0 = -Math.PI / 2 + (h / 24) * Math.PI * 2 + 0.02
    const a1 = a0 + (Math.PI * 2) / 24 - 0.04
    const past = h <= props.nowHour
    const r = R0 + (props.today[h] / max.value) * (R1 - R0)
    return { h, d: arc(CX, CY, R0, past ? Math.max(R0 + 1.5, r) : R0 + 3, a0, a1), past }
  })
)
const ring = computed(() => {
  let d = ''
  for (let i = 0; i <= 24; i++) {
    const h = i % 24
    const a = -Math.PI / 2 + (h / 24) * Math.PI * 2 + Math.PI / 24
    const r = R0 + (props.avg[h] / max.value) * (R1 - R0)
    d += `${i ? 'L' : 'M'}${(CX + r * Math.cos(a)).toFixed(1)} ${(CY + r * Math.sin(a)).toFixed(1)}`
  }
  return d
})
const peak = computed(() => {
  let h = 0
  props.today.forEach((v, i) => v > props.today[h] && (h = i))
  return { h, v: props.today[h] }
})
const total = computed(() => props.today.reduce((s, v) => s + v, 0))
const LABELS = [
  { l: '0h', dx: 0, dy: -1 },
  { l: '6h', dx: 1, dy: 0 },
  { l: '12h', dx: 0, dy: 1 },
  { l: '18h', dx: -1, dy: 0 },
]
const hh = (h: number) => String(h).padStart(2, '0') + ':00'
</script>

<template>
  <div>
    <svg class="chart" :viewBox="`0 0 ${W} ${H}`">
      <path
        v-for="b in bars"
        :key="b.h"
        :d="b.d"
        :fill="b.past ? 'var(--accent)' : 'var(--t5)'"
        @mousemove="show($event, `${hh(b.h)} · ${b.past ? fmtInt(today[b.h]) + ' today' : 'not yet'} · avg ${fmtInt(avg[b.h])}`)"
        @mouseleave="hide"
      />
      <path :d="ring" fill="none" stroke="var(--ink-3)" stroke-width="1.2" stroke-dasharray="2 3" pointer-events="none" />
      <text v-for="t in LABELS" :key="t.l" :x="CX + t.dx * (R1 + 13)" :y="CY + t.dy * (R1 + 13) + 4" text-anchor="middle">{{ t.l }}</text>
      <text :x="CX" :y="CY - 1" text-anchor="middle" style="font-size:17px;font-weight:600;fill:var(--ink)">{{ fmtInt(total) }}</text>
      <text :x="CX" :y="CY + 12" text-anchor="middle">today</text>
      <rect x="236" y="62" width="12" height="12" rx="3" fill="var(--accent)" />
      <text x="256" y="72" style="fill:var(--ink)">Today, by hour</text>
      <line x1="234" x2="250" y1="90" y2="90" stroke="var(--ink-3)" stroke-width="1.2" stroke-dasharray="2 3" />
      <text x="256" y="94" style="fill:var(--ink)">Hourly average</text>
      <text x="236" y="130">Peak so far</text>
      <text x="236" y="150" style="font-size:15px;font-weight:600;fill:var(--ink)">{{ total ? `${hh(peak.h)} · ${fmtInt(peak.v)}` : '—' }}</text>
    </svg>
    <div v-if="tip" class="chart-tip" :style="tipStyle()">{{ tip.text }}</div>
  </div>
</template>
