<script setup lang="ts">
import { computed } from 'vue'
import type { Attribution } from '../lib/api'

const props = defineProps<{ attribution: Attribution }>()

const max = computed(() => Math.max(...props.attribution.channels.map((c) => c.visitors), 1))
</script>

<template>
  <section class="card p-5">
    <div class="flex items-baseline mb-3">
      <h3 class="text-sm font-medium text-[var(--ink-2)]">Attribution <span class="font-normal text-[var(--ink-3)]">first touch · consented</span></h3>
      <span v-if="attribution.identified" class="ml-auto text-xs text-[var(--ink-3)]">{{ attribution.identified.toLocaleString() }} converted</span>
    </div>

    <p v-if="!attribution.identified" class="text-sm text-[var(--ink-3)]">
      No consented conversions yet — needs a goal plus visitors who accepted via <code class="rounded bg-[var(--bg)] px-1">melytics.consent(true)</code>.
    </p>

    <div v-else class="space-y-1.5">
      <div v-for="c in attribution.channels" :key="c.channel" class="flex items-center gap-2 text-xs tabular-nums">
        <span class="w-14 shrink-0 text-[var(--ink-2)]">{{ c.channel }}</span>
        <div class="h-4 flex-1 overflow-hidden rounded-md bg-[var(--bg)]">
          <div class="h-full rounded-md bg-[var(--accent-soft)]" :style="{ width: (c.visitors / max) * 100 + '%' }" />
        </div>
        <span class="w-8 shrink-0 text-right text-[var(--ink-3)]">{{ c.visitors }}</span>
      </div>
      <p class="pt-1.5 text-xs text-[var(--ink-3)]">Converting visitors credited to the channel that first brought them.</p>
    </div>
  </section>
</template>
