<script setup lang="ts">
import { computed } from 'vue'
import { useChartTip, fmtInt, pct, SPAN_W, type Span } from '../../lib/useChartTip'

/**
 * Fixed-width buckets as thin bars. Optional thresholds (good / poor) draw as
 * dashed status lines and step the bars past them down a tint; an optional
 * marker (p75, median) is the one direct label.
 */
const props = defineProps<{
  counts: number[]
  step: number
  labels?: string[]
  fmt?: (v: number) => string
  thresholds?: { good: number; poor: number }
  marker?: { value: number; label: string } | null
  unit?: string
  span?: Span
}>()
const { tip, show, hide, tipStyle } = useChartTip()
const H = 120
const W = computed(() => SPAN_W[props.span ?? 1])
const f = (v: number) => (props.fmt ? props.fmt(v) : String(Math.round(v * 100) / 100))
const bars = computed(() => {
  const n = props.counts.length
  const max = Math.max(1, ...props.counts)
  const total = props.counts.reduce((a, b) => a + b, 0)
  const bw = W.value / n
  return props.counts.map((c, i) => {
    const lo = i * props.step
    const fill = !props.thresholds ? 'var(--accent)' : lo < props.thresholds.good ? 'var(--accent)' : lo < props.thresholds.poor ? 'var(--t3)' : 'var(--t4)'
    const h = (c / max) * (H - 26)
    const label = props.labels?.[i] ?? (i === n - 1 ? `${f(lo)}+` : `${f(lo)}–${f(lo + props.step)}`)
    return { i, x: i * bw + 1, w: Math.max(1, bw - 2), y: H - h, h, fill, label, c, share: pct(c, total) }
  })
})
const xOf = (v: number) => Math.min(W.value, (v / (props.step * props.counts.length)) * W.value)
const ticks = computed(() => {
  if (props.labels) return props.labels.map((l, i) => ({ x: (i + 0.5) * (W.value / props.labels!.length), label: l, anchor: 'middle' }))
  const span = props.step * props.counts.length
  return [0, 0.25, 0.5, 0.75, 1].map((k) => ({ x: k * W.value, label: f(k * span), anchor: k === 0 ? 'start' : k === 1 ? 'end' : 'middle' }))
})
</script>

<template>
  <div>
    <svg class="chart" :viewBox="`0 0 ${W} ${H + 18}`">
      <rect
        v-for="b in bars"
        :key="b.i"
        :x="b.x"
        :y="b.y"
        :width="b.w"
        :height="b.h"
        :rx="Math.min(4, b.w / 2)"
        :fill="b.fill"
        @mousemove="show($event, `${b.label} · ${fmtInt(b.c)}${unit ? ' ' + unit : ''} · ${b.share}%`)"
        @mouseleave="hide"
      />
      <template v-if="thresholds">
        <line :x1="xOf(thresholds.good)" :x2="xOf(thresholds.good)" y1="4" :y2="H" stroke="var(--warn)" stroke-width="1" stroke-dasharray="3 3" pointer-events="none" />
        <text :x="xOf(thresholds.good) + 4" y="12" style="fill:var(--warn)">{{ f(thresholds.good) }}</text>
        <line :x1="xOf(thresholds.poor)" :x2="xOf(thresholds.poor)" y1="4" :y2="H" stroke="var(--down)" stroke-width="1" stroke-dasharray="3 3" pointer-events="none" />
        <text :x="xOf(thresholds.poor) + 4" y="12" style="fill:var(--down)">{{ f(thresholds.poor) }}</text>
      </template>
      <template v-if="marker">
        <line :x1="xOf(marker.value)" :x2="xOf(marker.value)" y1="22" :y2="H" stroke="var(--ink)" stroke-width="1.5" pointer-events="none" />
        <text :x="xOf(marker.value) + (xOf(marker.value) > W * 0.7 ? -5 : 5)" y="32" :text-anchor="xOf(marker.value) > W * 0.7 ? 'end' : 'start'" style="fill:var(--ink);font-weight:600">{{ marker.label }}</text>
      </template>
      <text v-for="t in ticks" :key="t.x" :x="t.x" :y="H + 14" :text-anchor="t.anchor">{{ t.label }}</text>
    </svg>
    <div v-if="tip" class="chart-tip" :style="tipStyle()">{{ tip.text }}</div>
  </div>
</template>
