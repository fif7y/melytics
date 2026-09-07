<script setup lang="ts">
import { computed } from 'vue'
import { useChartTip, tint, fmtInt, pct } from '../../lib/useChartTip'

export interface Slice { key: string; label: string; icon?: string; value: number }
const props = defineProps<{ slices: Slice[]; total?: number; unit?: string; selected?: string | null; clickable?: boolean; max?: number }>()
const emit = defineEmits<{ select: [key: string] }>()
const { tip, show, hide, tipStyle } = useChartTip()

// Top N in accent tints, the rest folded into one grey "Other" slice
const N = computed(() => props.max ?? 4)
const parts = computed(() => {
  const sorted = props.slices.slice().sort((a, b) => b.value - a.value)
  const head = sorted.slice(0, N.value)
  const rest = sorted.slice(N.value).reduce((s, x) => s + x.value, 0)
  return rest > 0 ? [...head, { key: '__other', label: 'Other', value: rest }] : head
})
const sum = computed(() => props.total ?? parts.value.reduce((s, x) => s + x.value, 0))

function arc(cx: number, cy: number, r0: number, r1: number, a0: number, a1: number) {
  // a full circle collapses to a single point; back off a hair so the path still draws
  if (a1 - a0 >= Math.PI * 2) a1 = a0 + Math.PI * 2 - 0.0001
  const p = (r: number, a: number) => [cx + r * Math.cos(a), cy + r * Math.sin(a)]
  const [x0, y0] = p(r1, a0), [x1, y1] = p(r1, a1), [x2, y2] = p(r0, a1), [x3, y3] = p(r0, a0)
  const L = a1 - a0 > Math.PI ? 1 : 0
  return `M${x0} ${y0}A${r1} ${r1} 0 ${L} 1 ${x1} ${y1}L${x2} ${y2}A${r0} ${r0} 0 ${L} 0 ${x3} ${y3}Z`
}
const arcs = computed(() => {
  let a = -Math.PI / 2
  return parts.value.map((p, i) => {
    const sw = sum.value ? (p.value / sum.value) * Math.PI * 2 : 0
    const d = arc(70, 70, 44, 66, a, a + sw)
    a += sw
    return { ...p, d, fill: p.key === '__other' ? 'var(--compare)' : tint(i) }
  })
})
const center = computed(() => (sum.value >= 10000 ? `${(sum.value / 1000).toFixed(1)}K` : fmtInt(sum.value)))
</script>

<template>
  <div class="grid grid-cols-[140px_1fr] items-center gap-4">
    <svg class="chart" viewBox="0 0 140 140" width="140" height="140" style="width:140px">
      <path
        v-for="a in arcs"
        :key="a.key"
        :d="a.d"
        :fill="a.fill"
        stroke="var(--surface)"
        stroke-width="2"
        stroke-linejoin="round"
        :class="clickable && a.key !== '__other' ? 'cursor-pointer' : ''"
        :opacity="selected && selected !== a.key ? 0.45 : 1"
        @mousemove="show($event, `${a.label} · ${fmtInt(a.value)} · ${pct(a.value, sum)}%`)"
        @mouseleave="hide"
        @click="clickable && a.key !== '__other' && emit('select', a.key)"
      />
      <text x="70" y="68" text-anchor="middle" style="font-size:20px;font-weight:600;fill:var(--ink)">{{ center }}</text>
      <text x="70" y="84" text-anchor="middle">{{ unit ?? 'views' }}</text>
    </svg>
    <ul class="min-w-0 space-y-1.5 text-sm">
      <li
        v-for="a in arcs"
        :key="a.key"
        class="flex items-center gap-2 rounded-md px-1 -mx-1"
        :class="[clickable && a.key !== '__other' ? 'cursor-pointer hover:bg-[color-mix(in_srgb,var(--ink)_4%,transparent)]' : '', selected === a.key ? 'font-medium text-[var(--accent)]' : '']"
        @click="clickable && a.key !== '__other' && emit('select', a.key)"
      >
        <i class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ background: a.fill }" />
        <span v-if="a.icon">{{ a.icon }}</span>
        <span class="min-w-0 flex-1 truncate">{{ a.label }}</span>
        <span class="tabular-nums text-[var(--ink-3)]">{{ pct(a.value, sum) }}%</span>
      </li>
    </ul>
    <div v-if="tip" class="chart-tip" :style="tipStyle()">{{ tip.text }}</div>
  </div>
</template>
