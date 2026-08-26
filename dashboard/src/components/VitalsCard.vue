<script setup lang="ts">
import { computed } from 'vue'

export interface Vitals {
  samples: number
  lcp: number | null
  cls: number | null
  inp: number | null
  ttfb: number | null
}

const props = defineProps<{ vitals: Vitals }>()

// Google CWV thresholds: [good ≤, poor >]
const METRICS = [
  { key: 'lcp', label: 'LCP', name: 'Largest Contentful Paint', desc: 'Time until the largest element is rendered — perceived load speed.', good: 2500, poor: 4000, fmt: (v: number) => `${(v / 1000).toFixed(2)}s` },
  { key: 'inp', label: 'INP', name: 'Interaction to Next Paint', desc: 'How quickly the page responds to clicks and taps — responsiveness.', good: 200, poor: 500, fmt: (v: number) => `${Math.round(v)}ms` },
  { key: 'cls', label: 'CLS', name: 'Cumulative Layout Shift', desc: 'How much the layout jumps around while loading — visual stability.', good: 0.1, poor: 0.25, fmt: (v: number) => v.toFixed(3) },
  { key: 'ttfb', label: 'TTFB', name: 'Time to First Byte', desc: 'How long the server takes to start responding — backend speed.', good: 800, poor: 1800, fmt: (v: number) => `${Math.round(v)}ms` },
] as const

const rows = computed(() =>
  METRICS.map((m) => {
    const v = props.vitals[m.key]
    return {
      ...m,
      value: v,
      text: v == null ? '—' : m.fmt(v),
      color: v == null ? 'var(--ink-3)' : v <= m.good ? 'var(--up)' : v > m.poor ? 'var(--down)' : 'var(--warn, #d97706)',
    }
  })
)
</script>

<template>
  <section class="card p-5">
    <div class="flex items-baseline mb-4">
      <h3 class="text-sm font-medium text-[var(--ink-2)]">Web Vitals <span class="font-normal text-[var(--ink-3)]">p75</span></h3>
      <span class="ml-auto text-xs text-[var(--ink-3)]">{{ vitals.samples.toLocaleString() }} samples</span>
    </div>

    <p v-if="!vitals.samples" class="text-sm text-[var(--ink-3)]">
      No samples yet — vitals arrive as visitors leave pages (needs tracker ≥ v1.1).
    </p>

    <div v-else class="grid grid-cols-4 gap-3">
      <div v-for="m in rows" :key="m.key" class="group relative cursor-help">
        <div class="text-xs text-[var(--ink-3)]">{{ m.label }}</div>
        <div class="text-xl font-semibold tabular-nums tracking-tight" :style="{ color: m.color }">
          {{ m.text }}
        </div>
        <div class="tip" role="tooltip">
          <div class="font-medium">{{ m.name }}</div>
          <div class="mt-0.5 text-[var(--ink-3)]">{{ m.desc }}</div>
          <div class="mt-1 text-[var(--ink-3)]">good ≤ {{ m.fmt(m.good) }} · poor &gt; {{ m.fmt(m.poor) }}</div>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.tip {
  position: absolute;
  bottom: calc(100% + 8px);
  left: 50%;
  transform: translateX(-50%) translateY(2px);
  width: 13.5rem;
  padding: 0.5rem 0.65rem;
  border-radius: 0.5rem;
  background: var(--bg);
  box-shadow: 0 4px 16px rgb(0 0 0 / 0.14), 0 1px 3px rgb(0 0 0 / 0.08);
  font-size: 0.75rem;
  line-height: 1.35;
  color: var(--ink);
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.12s ease, transform 0.12s ease;
  z-index: 10;
}
.group:hover .tip {
  opacity: 1;
  transform: translateX(-50%) translateY(0);
}
</style>
