<script setup lang="ts">
import { computed } from 'vue'
import { useChartTip, tint, fmtInt, pct } from '../../lib/useChartTip'
import type { Slice } from './Donut.vue'

const props = defineProps<{ slices: Slice[]; selected?: string | null; clickable?: boolean; max?: number }>()
const emit = defineEmits<{ select: [key: string] }>()
const { tip, show, hide, tipStyle } = useChartTip()

const parts = computed(() => {
  const sorted = props.slices.slice().sort((a, b) => b.value - a.value)
  const n = props.max ?? 4
  const head = sorted.slice(0, n)
  const rest = sorted.slice(n).reduce((s, x) => s + x.value, 0)
  const all = rest > 0 ? [...head, { key: '__other', label: 'Other', value: rest }] : head
  const sum = all.reduce((s, x) => s + x.value, 0)
  let x = 0
  return all.map((p, i) => {
    const w = sum ? (p.value / sum) * 100 : 0
    const out = { ...p, x, w, sum, fill: p.key === '__other' ? 'var(--compare)' : tint(i), dark: i < 2 }
    x += w
    return out
  })
})
</script>

<template>
  <div>
    <div class="flex h-8 w-full gap-0.5 overflow-hidden rounded-md">
      <div
        v-for="p in parts"
        :key="p.key"
        class="relative flex min-w-0 items-center overflow-hidden rounded-[4px] px-2 text-xs font-medium"
        :class="clickable && p.key !== '__other' ? 'cursor-pointer' : ''"
        :style="{ width: p.w + '%', background: p.fill, color: p.dark ? 'var(--bg)' : 'var(--ink)', opacity: selected && selected !== p.key ? 0.45 : 1 }"
        @mousemove="show($event, `${p.label} · ${fmtInt(p.value)} · ${pct(p.value, p.sum)}%`)"
        @mouseleave="hide"
        @click="clickable && p.key !== '__other' && emit('select', p.key)"
      >
        <span v-if="p.w > 14" class="truncate">{{ p.label }} {{ pct(p.value, p.sum) }}%</span>
      </div>
    </div>
    <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-sm">
      <li
        v-for="p in parts"
        :key="p.key"
        class="flex items-center gap-2"
        :class="[clickable && p.key !== '__other' ? 'cursor-pointer' : '', selected === p.key ? 'font-medium text-[var(--accent)]' : '']"
        @click="clickable && p.key !== '__other' && emit('select', p.key)"
      >
        <i class="h-2.5 w-2.5 rounded-full" :style="{ background: p.fill }" />
        <span v-if="p.icon">{{ p.icon }}</span>
        <span class="truncate">{{ p.label }}</span>
        <span class="tabular-nums text-[var(--ink-3)]">{{ fmtInt(p.value) }}</span>
      </li>
    </ul>
    <div v-if="tip" class="chart-tip" :style="tipStyle()">{{ tip.text }}</div>
  </div>
</template>
