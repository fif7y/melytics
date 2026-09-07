<script setup lang="ts">
import { computed } from 'vue'
import { useChartTip, SPAN_W, type Span } from '../../lib/useChartTip'

/** Last 60 seconds of pageviews as ticks; the newest land with a short fade. */
const props = defineProps<{ recent: number[]; span?: Span }>()
const { tip, show, hide, tipStyle } = useChartTip()
const H = 70
const W = computed(() => SPAN_W[props.span ?? 1])
const ticks = computed(() => {
  const per = new Array<number>(60).fill(0)
  props.recent.forEach((s) => per[59 - Math.min(59, Math.max(0, Math.floor(s)))]++)
  const bw = W.value / 60
  return per.map((n, i) => ({ i, n, x: i * bw + 1, w: bw - 2, h: n * 18, ago: 59 - i })).filter((t) => t.n)
})
</script>

<template>
  <div>
    <svg class="chart" :viewBox="`0 0 ${W} ${H + 16}`">
      <line x1="0" :x2="W" :y1="H" :y2="H" stroke="var(--grid)" />
      <rect
        v-for="t in ticks"
        :key="t.i"
        :x="t.x"
        :y="H - t.h"
        :width="t.w"
        :height="t.h"
        rx="2"
        :fill="t.ago < 5 ? 'var(--accent)' : t.ago < 20 ? 'var(--t2)' : 'var(--t3)'"
        :class="{ 'pulse-tick': t.ago < 5 }"
        @mousemove="show($event, `${t.ago}s ago · ${t.n} pageview${t.n > 1 ? 's' : ''}`)"
        @mouseleave="hide"
      />
      <text x="0" :y="H + 13">60s ago</text>
      <text :x="W" :y="H + 13" text-anchor="end">now</text>
    </svg>
    <div v-if="tip" class="chart-tip" :style="tipStyle()">{{ tip.text }}</div>
  </div>
</template>

<style scoped>
@keyframes pulse-in {
  from {
    opacity: 0;
    transform: translateY(3px);
  }
  to {
    opacity: 1;
    transform: none;
  }
}
.pulse-tick {
  animation: pulse-in 0.4s ease-out;
}
@media (prefers-reduced-motion: reduce) {
  .pulse-tick {
    animation: none;
  }
}
</style>
