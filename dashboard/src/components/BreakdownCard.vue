<script setup lang="ts">
import type { BreakdownRow } from '../lib/api'

const props = defineProps<{ title: string; rows: BreakdownRow[]; empty?: string }>()

function pct(row: BreakdownRow) {
  const max = Math.max(...props.rows.map((r) => r.pageviews), 1)
  return (row.pageviews / max) * 100
}
</script>

<template>
  <section class="card p-5">
    <h3 class="text-sm font-medium text-[var(--ink-2)] mb-4">{{ title }}</h3>
    <p v-if="!rows.length" class="text-sm text-[var(--ink-3)]">{{ empty ?? 'No data yet' }}</p>
    <ul class="space-y-1.5">
      <li v-for="row in rows" :key="row.value" class="relative flex items-center gap-3 rounded-md px-2.5 py-1.5 overflow-hidden">
        <span
          class="absolute inset-y-0 left-0 rounded-md bg-[var(--accent-soft)]"
          :style="{ width: pct(row) + '%' }"
        />
        <span class="relative flex-1 truncate text-sm">{{ row.value }}</span>
        <span class="relative text-sm tabular-nums text-[var(--ink-2)]">{{ row.pageviews.toLocaleString() }}</span>
      </li>
    </ul>
  </section>
</template>
