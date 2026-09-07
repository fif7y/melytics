<script setup lang="ts">
import { computed } from 'vue'
import type { PathRow } from '../lib/api'
import Sankey from './charts/Sankey.vue'

/** Where visitors come from, where they land, and what happens next. */
const props = defineProps<{ paths: PathRow[]; hasGoals: boolean }>()
const total = computed(() => props.paths.reduce((s, r) => s + r.sessions, 0))
const converted = computed(() => props.paths.filter((r) => r.outcome === 'converted').reduce((s, r) => s + r.sessions, 0))
</script>

<template>
  <section class="card p-5">
    <div class="mb-4 flex flex-wrap items-baseline gap-2">
      <h3 class="text-sm font-medium text-[var(--ink-2)]">Paths</h3>
      <span class="text-xs text-[var(--ink-3)]">source → landing page → outcome</span>
      <span v-if="total && hasGoals" class="ml-auto text-xs tabular-nums text-[var(--ink-3)]">{{ Math.round((converted / total) * 100) }}% of {{ total.toLocaleString() }} sessions converted</span>
    </div>
    <p v-if="!total" class="text-sm text-[var(--ink-3)]">No sessions yet</p>
    <template v-else>
      <Sankey :rows="paths" />
      <p v-if="!hasGoals" class="mt-3 text-xs text-[var(--ink-3)]">Add a goal to see which sources convert — without one, outcomes are bounced or kept browsing.</p>
    </template>
  </section>
</template>
