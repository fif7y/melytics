<script setup lang="ts">
import { computed } from 'vue'
import type { Bots } from '../lib/api'

// humans = pageviews recorded over the same range, for the share-of-traffic split
const props = defineProps<{ bots: Bots; humans: number }>()

const pct = computed(() => {
  const all = props.bots.total + props.humans
  return all ? Math.round((props.bots.total / all) * 100) : 0
})
const max = computed(() => Math.max(...props.bots.names.map((r) => r.pageviews), 1))
</script>

<template>
  <section class="card p-5">
    <h3 class="text-sm font-medium text-[var(--ink-2)] mb-4">Bots</h3>
    <p v-if="!bots.total" class="text-sm text-[var(--ink-3)]">No bot traffic blocked</p>
    <template v-else>
      <div class="mb-4">
        <div class="flex items-baseline justify-between gap-3">
          <span class="text-sm font-medium">{{ pct }}% of traffic</span>
          <span class="text-xs tabular-nums text-[var(--ink-3)]">
            {{ bots.total.toLocaleString() }} blocked · {{ humans.toLocaleString() }} human
          </span>
        </div>
        <!-- accent = the site's real traffic, muted = what got turned away -->
        <div class="mt-2 flex h-1.5 overflow-hidden rounded-full bg-[var(--accent-soft)]">
          <span
            class="rounded-full bg-[color-mix(in_srgb,var(--ink)_35%,transparent)]"
            :style="{ width: pct + '%' }"
          />
        </div>
      </div>
      <ul class="space-y-1.5">
        <li
          v-for="row in bots.names"
          :key="row.value"
          class="relative flex items-center gap-3 rounded-md px-2.5 py-1.5 overflow-hidden select-none"
        >
          <span
            class="absolute inset-y-0 left-0 rounded-md bg-[color-mix(in_srgb,var(--ink)_6%,transparent)]"
            :style="{ width: (row.pageviews / max) * 100 + '%' }"
          />
          <span class="relative flex-1 truncate text-sm">{{ row.value }}</span>
          <span class="relative text-sm tabular-nums text-[var(--ink-2)]">{{ row.pageviews.toLocaleString() }}</span>
        </li>
      </ul>
    </template>
  </section>
</template>
