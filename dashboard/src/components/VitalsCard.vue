<script setup lang="ts">
import { computed, ref } from 'vue'

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

// Five layouts, user-selectable and persisted (Tiles is the default)
const LAYOUTS = [
  { key: 'tiles', label: 'Tiles' },
  { key: 'tracks', label: 'Tracks' },
  { key: 'gauges', label: 'Gauges' },
  { key: 'bullet', label: 'Bullet' },
  { key: 'scoreline', label: 'Scoreline' },
] as const
type LayoutKey = (typeof LAYOUTS)[number]['key']
const LAYOUT_KEY = 'melytics_vitals_layout'
const layout = ref<LayoutKey>(
  (() => {
    const v = localStorage.getItem(LAYOUT_KEY)
    return LAYOUTS.some((l) => l.key === v) ? (v as LayoutKey) : 'tiles'
  })()
)
const layoutMenu = ref(false)
function setLayout(k: LayoutKey) {
  layout.value = k
  layoutMenu.value = false
  try {
    localStorage.setItem(LAYOUT_KEY, k)
  } catch {}
}

// The threshold scale maps value → position: good zone 0–55%, needs-improvement 55–80%, poor 80–100%
function pos(v: number, good: number, poor: number) {
  if (v <= good) return (v / good) * 55
  if (v <= poor) return 55 + ((v - good) / (poor - good)) * 25
  return Math.min(100, 80 + ((v - poor) / (poor * 0.6)) * 20)
}

const rows = computed(() =>
  METRICS.map((m) => {
    const v = props.vitals[m.key]
    const verdict = v == null ? null : v <= m.good ? 'good' : v > m.poor ? 'poor' : 'ni'
    return {
      ...m,
      value: v,
      text: v == null ? '—' : m.fmt(v),
      pos: v == null ? null : pos(v, m.good, m.poor),
      verdict,
      verdictLabel: verdict === 'good' ? 'good' : verdict === 'ni' ? 'needs work' : verdict === 'poor' ? 'poor' : '',
      color: v == null ? 'var(--ink-3)' : verdict === 'good' ? 'var(--up)' : verdict === 'poor' ? 'var(--down)' : 'var(--warn, #d97706)',
    }
  })
)

// Worst metric wins the overall verdict (scoreline)
const overall = computed(() => {
  const vs = rows.value.map((r) => r.verdict).filter(Boolean)
  if (!vs.length) return null
  return vs.includes('poor') ? { label: 'Poor', color: 'var(--down)' } : vs.includes('ni') ? { label: 'Needs work', color: 'var(--warn, #d97706)' } : { label: 'Good', color: 'var(--up)' }
})

// Half-circle arc r=36 → length ≈ 113
const ARC = Math.PI * 36
</script>

<template>
  <section class="card p-5">
    <div class="flex items-center mb-3">
      <h3 class="text-sm font-medium text-[var(--ink-2)]">Web Vitals <span class="font-normal text-[var(--ink-3)]">p75</span></h3>
      <div class="relative ml-2">
        <button
          class="flex h-6 w-6 items-center justify-center rounded-md text-[var(--ink-3)] hover:bg-[var(--bg)] hover:text-[var(--ink)]"
          title="Vitals layout"
          aria-label="Choose vitals layout"
          @click="layoutMenu = !layoutMenu"
        >
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
            <path d="M4 6h16M4 12h10M4 18h5" />
          </svg>
        </button>
        <div v-if="layoutMenu" class="absolute left-0 top-full z-20 mt-1 w-32 rounded-xl bg-[var(--surface)] py-1 shadow-xl">
          <button
            v-for="l in LAYOUTS"
            :key="l.key"
            class="flex w-full items-center px-3 py-1.5 text-left text-sm hover:bg-[var(--bg)]"
            :class="layout === l.key ? 'text-[var(--accent)] font-medium' : ''"
            @click="setLayout(l.key)"
          >
            {{ l.label }}
          </button>
        </div>
      </div>
      <span class="ml-auto text-xs text-[var(--ink-3)]">{{ vitals.samples.toLocaleString() }} samples</span>
    </div>

    <p v-if="!vitals.samples" class="text-sm text-[var(--ink-3)]">
      No samples yet — vitals arrive as visitors leave pages (needs tracker ≥ v1.1).
    </p>

    <!-- Tiles: 2×2 stat tiles with verdict pills -->
    <div v-else-if="layout === 'tiles'" class="grid grid-cols-2 gap-2.5">
      <div v-for="m in rows" :key="m.key" class="group relative cursor-help rounded-[10px] bg-[var(--bg)] px-3.5 py-3">
        <div class="flex items-center justify-between text-[11px] text-[var(--ink-3)]">
          {{ m.label }}
          <span
            v-if="m.verdict"
            class="rounded-full px-2 py-0.5 text-[10px] font-medium"
            :style="{ color: m.color, background: `color-mix(in srgb, ${m.color} 13%, transparent)` }"
          >{{ m.verdictLabel }}</span>
        </div>
        <div class="mt-0.5 text-xl font-semibold tabular-nums tracking-tight">{{ m.text }}</div>
        <div class="tip" role="tooltip">
          <div class="font-medium">{{ m.name }}</div>
          <div class="mt-0.5 text-[var(--ink-3)]">{{ m.desc }}</div>
          <div class="mt-1 text-[var(--ink-3)]">good ≤ {{ m.fmt(m.good) }} · poor &gt; {{ m.fmt(m.poor) }}</div>
        </div>
      </div>
    </div>

    <!-- Gauges: four half-donuts, value in the mouth with breathing room -->
    <div v-else-if="layout === 'gauges'" class="grid grid-cols-4 gap-2 text-center">
      <div v-for="m in rows" :key="m.key" class="group relative cursor-help">
        <svg viewBox="0 0 84 46" class="mx-auto block h-10 w-full max-w-[84px]" aria-hidden="true">
          <path d="M6 42 A36 36 0 0 1 78 42" fill="none" stroke="color-mix(in srgb, var(--ink) 10%, transparent)" stroke-width="6" stroke-linecap="round" />
          <path
            v-if="m.pos != null"
            d="M6 42 A36 36 0 0 1 78 42"
            fill="none"
            :stroke="m.color"
            stroke-width="6"
            stroke-linecap="round"
            :stroke-dasharray="`${Math.max((m.pos / 100) * ARC, 4)} ${ARC}`"
          />
        </svg>
        <div class="mt-1.5 text-sm font-semibold tabular-nums" :style="{ color: m.color }">{{ m.text }}</div>
        <div class="mt-0.5 text-[11px] text-[var(--ink-3)]">{{ m.label }}</div>
        <div class="tip" role="tooltip">
          <div class="font-medium">{{ m.name }}</div>
          <div class="mt-0.5 text-[var(--ink-3)]">{{ m.desc }}</div>
          <div class="mt-1 text-[var(--ink-3)]">good ≤ {{ m.fmt(m.good) }} · poor &gt; {{ m.fmt(m.poor) }}</div>
        </div>
      </div>
    </div>

    <!-- Scoreline: one verdict, numbers inline -->
    <div v-else-if="layout === 'scoreline'" class="flex flex-wrap items-center gap-x-3.5 gap-y-2 text-[13px]">
      <span
        v-if="overall"
        class="rounded-full px-2.5 py-1 text-xs font-semibold"
        :style="{ color: overall.color, background: `color-mix(in srgb, ${overall.color} 14%, transparent)` }"
      >{{ overall.label }}</span>
      <span v-for="m in rows" :key="m.key" class="group relative flex cursor-help items-baseline gap-1.5">
        <span class="text-[11px] text-[var(--ink-3)]">{{ m.label }}</span>
        <b class="font-semibold tabular-nums" :style="{ color: m.color }">{{ m.text }}</b>
        <span class="tip" role="tooltip">
          <span class="block font-medium">{{ m.name }}</span>
          <span class="mt-0.5 block text-[var(--ink-3)]">{{ m.desc }}</span>
          <span class="mt-1 block text-[var(--ink-3)]">good ≤ {{ m.fmt(m.good) }} · poor &gt; {{ m.fmt(m.poor) }}</span>
        </span>
      </span>
    </div>

    <!-- Tracks (dot) and Bullet (filled bar) share the row geometry -->
    <div v-else>
      <div v-for="m in rows" :key="m.key" class="group relative vrow cursor-help">
        <span class="text-xs font-medium text-[var(--ink-2)]">{{ m.label }}</span>
        <div v-if="layout === 'tracks'" class="track">
          <span v-if="m.pos != null" class="dot" :style="{ left: m.pos + '%', background: m.color }" />
        </div>
        <div v-else class="bullet">
          <span v-if="m.pos != null" class="bfill" :style="{ width: Math.max(m.pos, 2) + '%', background: m.color }" />
        </div>
        <span class="text-right text-sm font-semibold tabular-nums" :style="{ color: m.color }">{{ m.text }}</span>
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
.vrow {
  display: grid;
  grid-template-columns: 2.8rem 1fr 4.2rem;
  gap: 1rem;
  align-items: center;
  padding: 0.55rem 0;
}
.track {
  position: relative;
  height: 6px;
  border-radius: 3px;
  background: linear-gradient(
    to right,
    color-mix(in srgb, var(--up) 28%, var(--surface)) 0 55%,
    color-mix(in srgb, var(--warn, #d97706) 28%, var(--surface)) 55% 80%,
    color-mix(in srgb, var(--down) 26%, var(--surface)) 80% 100%
  );
}
.dot {
  position: absolute;
  top: 50%;
  width: 13px;
  height: 13px;
  border-radius: 50%;
  transform: translate(-50%, -50%);
  box-shadow: 0 0 0 3px var(--surface), var(--shadow);
}
.bullet {
  position: relative;
  height: 12px;
  border-radius: 4px;
  overflow: hidden;
  background: linear-gradient(
    to right,
    color-mix(in srgb, var(--up) 14%, var(--surface)) 0 55%,
    color-mix(in srgb, var(--warn, #d97706) 14%, var(--surface)) 55% 80%,
    color-mix(in srgb, var(--down) 12%, var(--surface)) 80% 100%
  );
}
.bfill {
  position: absolute;
  top: 3px;
  bottom: 3px;
  left: 0;
  border-radius: 3px;
}
.tip {
  position: absolute;
  bottom: calc(100% + 6px);
  left: 50%;
  transform: translateX(-50%) translateY(2px);
  width: 15rem;
  padding: 0.5rem 0.65rem;
  border-radius: 0.5rem;
  background: var(--bg);
  box-shadow: 0 4px 16px rgb(0 0 0 / 0.14), 0 1px 3px rgb(0 0 0 / 0.08);
  font-size: 0.75rem;
  line-height: 1.35;
  color: var(--ink);
  text-align: left;
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
