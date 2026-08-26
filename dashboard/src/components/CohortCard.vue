<script setup lang="ts">
import { computed } from 'vue'
import type { CohortRow } from '../lib/api'

const props = defineProps<{ cohorts: CohortRow[]; tier2Enabled: boolean }>()

const hasData = computed(() => props.cohorts.some((c) => c.size > 0))
const maxCols = computed(() => Math.max(...props.cohorts.map((c) => c.active.length), 0))

function pct(c: CohortRow, i: number): number | null {
  if (i >= c.active.length) return null
  return c.size ? Math.round((c.active[i] / c.size) * 100) : 0
}

function cellStyle(p: number) {
  // low-alpha accent fill scaled by retention %, never a border
  return { background: `color-mix(in srgb, var(--accent) ${Math.max(p * 0.85, p > 0 ? 12 : 0)}%, var(--bg))` }
}

const weekLabel = (w: string) =>
  new Date(w + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
</script>

<template>
  <section class="card p-5">
    <div class="flex items-baseline mb-3">
      <h3 class="text-sm font-medium text-[var(--ink-2)]">Cohorts <span class="font-normal text-[var(--ink-3)]">weekly · consented</span></h3>
    </div>

    <p v-if="!tier2Enabled" class="text-sm text-[var(--ink-3)]">
      Tier-2 tracking is off. Enable it in Settings, then call <code class="rounded bg-[var(--bg)] px-1">melytics.consent(true)</code> from your consent banner.
    </p>
    <p v-else-if="!hasData" class="text-sm text-[var(--ink-3)]">
      No consented visitors yet — cohorts fill in as visitors accept and return week over week.
    </p>

    <div v-else class="overflow-x-auto">
      <table class="w-full text-xs tabular-nums">
        <thead>
          <tr class="text-[var(--ink-3)]">
            <th class="pb-1.5 pr-2 text-left font-normal">Week</th>
            <th class="pb-1.5 pr-2 text-right font-normal">Size</th>
            <th v-for="i in maxCols - 1" :key="i" class="pb-1.5 text-center font-normal">+{{ i }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in cohorts" :key="c.week">
            <td class="py-0.5 pr-2 whitespace-nowrap text-[var(--ink-2)]">{{ weekLabel(c.week) }}</td>
            <td class="py-0.5 pr-2 text-right text-[var(--ink-2)]">{{ c.size }}</td>
            <td v-for="i in maxCols - 1" :key="i" class="p-0.5">
              <div
                v-if="pct(c, i) !== null && c.size"
                class="flex h-6 min-w-8 items-center justify-center rounded-md"
                :style="cellStyle(pct(c, i)!)"
                :title="`${c.active[i]} of ${c.size} back in week +${i}`"
              >
                {{ pct(c, i) }}%
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
