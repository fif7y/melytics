<script setup lang="ts">
import { computed } from 'vue'
import type { TimeToConvert } from '../lib/api'

const props = defineProps<{ ttc: TimeToConvert }>()

const max = computed(() => Math.max(...props.ttc.buckets.map((b) => b.visitors), 1))
</script>

<template>
  <section class="card p-5">
    <div class="flex items-baseline mb-3">
      <h3 class="text-sm font-medium text-[var(--ink-2)]">Time to convert <span class="font-normal text-[var(--ink-3)]">consented</span></h3>
      <span v-if="ttc.identified" class="ml-auto text-xs text-[var(--ink-3)]">{{ ttc.identified.toLocaleString() }} converted</span>
    </div>

    <p v-if="!ttc.identified" class="text-sm text-[var(--ink-3)]">
      No consented conversions yet — needs a goal plus visitors who accepted via <code class="rounded bg-[var(--bg)] px-1">melytics.consent(true)</code>.
    </p>

    <div v-else>
      <div class="mb-3 flex items-baseline gap-4">
        <div class="flex items-baseline gap-1.5">
          <span class="text-2xl font-semibold tabular-nums">{{ ttc.median_days }}</span>
          <span class="text-sm text-[var(--ink-3)]">median days</span>
        </div>
        <div class="flex items-baseline gap-1.5">
          <span class="text-2xl font-semibold tabular-nums">{{ ttc.median_sessions }}</span>
          <span class="text-sm text-[var(--ink-3)]">visits</span>
        </div>
      </div>
      <div class="space-y-1.5">
        <div v-for="b in ttc.buckets" :key="b.label" class="flex items-center gap-2 text-xs tabular-nums">
          <span class="w-14 shrink-0 text-[var(--ink-2)]">{{ b.label }}</span>
          <div class="h-4 flex-1 overflow-hidden rounded-md bg-[var(--bg)]">
            <div class="h-full rounded-md bg-[var(--accent-soft)]" :style="{ width: (b.visitors / max) * 100 + '%' }" />
          </div>
          <span class="w-8 shrink-0 text-right text-[var(--ink-3)]">{{ b.visitors }}</span>
        </div>
      </div>
    </div>
  </section>
</template>
