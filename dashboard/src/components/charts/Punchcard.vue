<script setup lang="ts">
import { computed } from 'vue'
import { useChartTip, fmtInt } from '../../lib/useChartTip'

/** 7 × 24 grid of visitors per weekday/hour; sequential = one hue, light → dark. */
const props = defineProps<{ grid: number[][]; days: number }>()
const { tip, show, hide, tipStyle } = useChartTip()
const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']
const W = 400, L = 34, CH = 17
const cw = (W - L) / 24
const cells = computed(() => {
  const max = Math.max(1, ...props.grid.flat())
  let peak = { d: 0, h: 0, v: -1 }
  const out: { d: number; h: number; v: number; mix: number }[] = []
  props.grid.forEach((row, d) =>
    row.forEach((v, h) => {
      if (v > peak.v) peak = { d, h, v }
      out.push({ d, h, v, mix: Math.round(8 + 92 * Math.pow(v / max, 1.3)) })
    })
  )
  return { out, peak }
})
// grid holds sums over the range; the tooltip adds a per-day average
const perDay = (v: number) => Math.round(v / Math.max(1, props.days / 7))
</script>

<template>
  <div>
    <svg class="chart" :viewBox="`0 0 ${W} ${7 * CH + 20}`">
      <text v-for="(d, i) in DAYS" :key="d" x="0" :y="i * CH + 12">{{ d }}</text>
      <rect
        v-for="c in cells.out"
        :key="c.d * 24 + c.h"
        :x="L + c.h * cw + 1"
        :y="c.d * CH + 1"
        :width="cw - 2"
        :height="CH - 2"
        rx="3"
        :fill="`color-mix(in srgb, var(--accent) ${c.mix}%, var(--surface))`"
        @mousemove="show($event, `${DAYS[c.d]} ${String(c.h).padStart(2, '0')}:00 · ${fmtInt(c.v)} visitors · ~${perDay(c.v)} per ${DAYS[c.d]}`)"
        @mouseleave="hide"
      />
      <text v-if="cells.peak.v > 0" :x="L + cells.peak.h * cw + cw / 2" :y="cells.peak.d * CH + 12" text-anchor="middle" style="font-size:9px;font-weight:600;fill:var(--bg)" pointer-events="none">{{ cells.peak.v >= 1000 ? Math.round(cells.peak.v / 1000) + 'k' : cells.peak.v }}</text>
      <text v-for="h in [0, 6, 12, 18, 23]" :key="h" :x="L + h * cw + cw / 2" :y="7 * CH + 14" text-anchor="middle">{{ h }}h</text>
    </svg>
    <div v-if="tip" class="chart-tip" :style="tipStyle()">{{ tip.text }}</div>
  </div>
</template>
