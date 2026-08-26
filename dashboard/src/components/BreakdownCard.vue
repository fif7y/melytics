<script setup lang="ts">
import { computed } from 'vue'
import type { BreakdownRow } from '../lib/api'

const props = defineProps<{ title: string; rows: BreakdownRow[]; empty?: string; selected?: string | null; dim?: string; clickable?: boolean }>()
const emit = defineEmits<{ select: [value: string] }>()

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
function display(row: BreakdownRow) {
  if (props.dim !== 'country' || !/^[A-Za-z]{2}$/.test(row.value)) return { icon: '', label: row.value }
  const cc = row.value.toUpperCase()
  return {
    icon: cc.replace(/./g, (c) => String.fromCodePoint(127397 + c.charCodeAt(0))),
    label: regionNames?.of(cc) ?? cc,
  }
}
</script>

<template>
  <section class="card p-5">
    <h3 class="text-sm font-medium text-[var(--ink-2)] mb-4">{{ title }}</h3>
    <p v-if="!rows.length" class="text-sm text-[var(--ink-3)]">{{ empty ?? 'No data yet' }}</p>
    <ul class="space-y-1.5">
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
        <span class="relative w-9 text-right text-xs tabular-nums text-[var(--ink-3)] opacity-0 transition-opacity group-hover/row:opacity-100">
          {{ Math.round((row.pageviews / (total || 1)) * 100) }}%
        </span>
        <span class="relative text-sm tabular-nums text-[var(--ink-2)]">{{ row.pageviews.toLocaleString() }}</span>
      </li>
    </ul>
  </section>
</template>
