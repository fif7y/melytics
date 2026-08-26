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
  { key: 'lcp', label: 'LCP', good: 2500, poor: 4000, fmt: (v: number) => `${(v / 1000).toFixed(2)}s` },
  { key: 'inp', label: 'INP', good: 200, poor: 500, fmt: (v: number) => `${Math.round(v)}ms` },
  { key: 'cls', label: 'CLS', good: 0.1, poor: 0.25, fmt: (v: number) => v.toFixed(3) },
  { key: 'ttfb', label: 'TTFB', good: 800, poor: 1800, fmt: (v: number) => `${Math.round(v)}ms` },
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
      <div v-for="m in rows" :key="m.key">
        <div class="text-xs text-[var(--ink-3)]">{{ m.label }}</div>
        <div class="text-xl font-semibold tabular-nums tracking-tight" :style="{ color: m.color }">
          {{ m.text }}
        </div>
      </div>
    </div>
  </section>
</template>
