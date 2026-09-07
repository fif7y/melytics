<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { BreakdownRow } from '../lib/api'
import LayoutMenu from './LayoutMenu.vue'
import Donut from './charts/Donut.vue'
import Strip from './charts/Strip.vue'
import Treemap from './charts/Treemap.vue'
import Slope from './charts/Slope.vue'

import { BREAKDOWN_LAYOUTS, LIVE_LAYOUTS, type BreakdownLayout, type LiveLayout } from '../lib/layouts'
import Pulse from './charts/Pulse.vue'

const PATH_DIMS = ['page', 'entry_page', 'exit_page', 'not_found']

const props = defineProps<{
  title: string
  rows: BreakdownRow[]
  empty?: string
  selected?: string | null
  dim?: string
  clickable?: boolean
  live?: boolean
  layout?: BreakdownLayout
  /** previous-period rows (compare / trend layouts) */
  previous?: BreakdownRow[] | null
  /** per-value series across the range (trend layout) */
  trend?: Record<string, number[]> | null
  /** parent can fetch compare / trend data on request */
  canCompare?: boolean
  compareLabels?: { from: string; to: string }
  /** live card: seconds-ago of the last minute's pageviews (pulse layout) */
  recent?: number[] | null
  liveLayout?: LiveLayout
}>()
const emit = defineEmits<{ select: [value: string]; 'update:layout': [layout: BreakdownLayout]; 'update:liveLayout': [layout: LiveLayout] }>()

// Controlled when the parent passes `layout`; otherwise (public share) local.
const local = ref<BreakdownLayout>('list')
const layout = computed(() => props.layout ?? local.value)
function setLayout(k: BreakdownLayout) {
  local.value = k
  emit('update:layout', k)
}
const options = computed(() =>
  BREAKDOWN_LAYOUTS.filter((l) => {
    if (l.key === 'treemap') return PATH_DIMS.includes(props.dim ?? '')
    if (l.key === 'compare' || l.key === 'trend') return !!props.canCompare
    return true
  })
)
// A layout that lost its data source (e.g. the share view) falls back to the list
watch(options, (o) => {
  if (!o.some((l) => l.key === layout.value)) setLayout('list')
})

const max = computed(() => Math.max(...props.rows.map((r) => r.pageviews), 1))
const total = computed(() => props.rows.reduce((s, r) => s + r.pageviews, 0))

// Countries arrive as ISO codes; render flag + full name, filter still uses the raw code.
const regionNames = (() => {
  try {
    return new Intl.DisplayNames(['en'], { type: 'region' })
  } catch {
    return null
  }
})()
function display(row: { value: string }) {
  if (props.dim !== 'country' || !/^[A-Za-z]{2}$/.test(row.value)) return { icon: '', label: row.value }
  const cc = row.value.toUpperCase()
  return {
    icon: cc.replace(/./g, (c) => String.fromCodePoint(127397 + c.charCodeAt(0))),
    label: regionNames?.of(cc) ?? cc,
  }
}
const slices = computed(() => props.rows.map((r) => ({ key: r.value, ...display(r), value: r.pageviews })))
const compareRows = computed(() => {
  const prev = new Map((props.previous ?? []).map((r) => [r.value, r.pageviews]))
  return props.rows.map((r) => ({ key: r.value, ...display(r), now: r.pageviews, before: prev.get(r.value) ?? 0 }))
})
const delta = (row: BreakdownRow) => {
  const before = (props.previous ?? []).find((p) => p.value === row.value)?.pageviews
  return before ? Math.round(((row.pageviews - before) / before) * 100) : null
}
// 70×20 sparkline per row, rising rows in accent, falling in grey
function spark(values: number[] | undefined) {
  if (!values || values.length < 2) return null
  const mx = Math.max(...values), mn = Math.min(...values)
  const y = (v: number) => 16 - ((v - mn) / (mx - mn || 1)) * 14
  const d = values.map((v, i) => `${i ? 'L' : 'M'}${((i / (values.length - 1)) * 70).toFixed(1)} ${y(v).toFixed(1)}`).join(' ')
  return { d, ex: 70, ey: y(values[values.length - 1]) }
}
</script>

<template>
  <section class="card p-5">
    <div class="mb-4 flex items-center gap-2">
      <h3 class="flex items-center gap-2 text-sm font-medium text-[var(--ink-2)]">
        <span v-if="live" class="h-2 w-2 rounded-full bg-[var(--up)]" :class="{ 'animate-pulse': rows.length }" />
        {{ title }}
      </h3>
      <LayoutMenu v-if="live && recent" class="-my-1 ml-auto" :options="LIVE_LAYOUTS" :model-value="liveLayout ?? 'list'" title="Live layout" @update:model-value="emit('update:liveLayout', $event)" />
      <LayoutMenu v-else-if="!live && rows.length" class="-my-1 ml-auto" :options="options" :model-value="layout" :title="`${title} layout`" @update:model-value="setLayout" />
    </div>
    <template v-if="live && liveLayout === 'pulse' && recent">
      <p class="mb-2 text-sm"><b class="text-lg font-semibold tabular-nums">{{ rows.reduce((s, r) => s + r.pageviews, 0) }}</b> <span class="text-[var(--ink-3)]">on the site now</span></p>
      <Pulse :recent="recent" />
      <p v-if="rows.length" class="mt-2 truncate text-xs text-[var(--ink-3)]">{{ rows.map((r) => r.value).join(' · ') }}</p>
    </template>
    <p v-else-if="!rows.length" class="text-sm text-[var(--ink-3)]">{{ empty ?? 'No data yet' }}</p>

    <Donut v-else-if="layout === 'donut'" :slices="slices" :selected="selected" :clickable="clickable" @select="(v) => emit('select', v)" />
    <Strip v-else-if="layout === 'strip'" :slices="slices" :selected="selected" :clickable="clickable" @select="(v) => emit('select', v)" />
    <Treemap v-else-if="layout === 'treemap'" :rows="rows" :selected="selected" :clickable="clickable" @select="(v) => emit('select', v)" />
    <Slope
      v-else-if="layout === 'compare'"
      :rows="compareRows"
      :from-label="compareLabels?.from ?? 'before'"
      :to-label="compareLabels?.to ?? 'now'"
      :selected="selected"
      :clickable="clickable"
      @select="(v) => emit('select', v)"
    />

    <ul v-else class="space-y-1.5">
      <li
        v-for="row in rows"
        :key="row.value"
        class="group/row relative flex items-center gap-3 rounded-md px-2.5 py-1.5 overflow-hidden select-none"
        :class="clickable ? 'cursor-pointer hover:bg-[color-mix(in_srgb,var(--ink)_4%,transparent)]' : ''"
        :title="clickable ? (selected === row.value ? 'Clear filter' : `Filter dashboard by ${row.value}`) : undefined"
        @click="clickable && emit('select', row.value)"
      >
        <span
          class="absolute inset-y-0 left-0 rounded-md bg-[var(--accent-soft)]"
          :style="{ width: (row.pageviews / max) * 100 + '%' }"
        />
        <span v-if="display(row).icon" class="relative">{{ display(row).icon }}</span>
        <span class="relative flex-1 truncate text-sm" :class="{ 'font-medium text-[var(--accent)]': selected === row.value }">
          {{ display(row).label }}
        </span>
        <template v-if="layout === 'trend'">
          <svg v-if="spark(trend?.[row.value])" class="relative shrink-0" viewBox="-2 -2 74 22" width="70" height="20">
            <path :d="spark(trend?.[row.value])!.d" fill="none" :stroke="(delta(row) ?? 0) >= 0 ? 'var(--accent)' : 'var(--compare)'" stroke-width="1.6" stroke-linejoin="round" />
            <circle :cx="spark(trend?.[row.value])!.ex" :cy="spark(trend?.[row.value])!.ey" r="2.5" :fill="(delta(row) ?? 0) >= 0 ? 'var(--accent)' : 'var(--compare)'" />
          </svg>
          <span class="relative w-12 text-right text-xs tabular-nums" :class="delta(row) === null ? 'text-[var(--ink-3)]' : delta(row)! >= 0 ? 'text-[var(--up)]' : 'text-[var(--down)]'">
            {{ delta(row) === null ? 'new' : `${delta(row)! >= 0 ? '↑' : '↓'}${Math.abs(delta(row)!)}%` }}
          </span>
        </template>
        <span v-else class="relative w-9 text-right text-xs tabular-nums text-[var(--ink-3)] opacity-0 transition-opacity group-hover/row:opacity-100">
          {{ Math.round((row.pageviews / (total || 1)) * 100) }}%
        </span>
        <span class="relative text-sm tabular-nums text-[var(--ink-2)]">{{ row.pageviews.toLocaleString() }}</span>
      </li>
    </ul>
  </section>
</template>
