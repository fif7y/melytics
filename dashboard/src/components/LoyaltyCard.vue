<script setup lang="ts">
import { computed } from 'vue'
import type { Loyalty } from '../lib/api'

const props = defineProps<{ loyalty: Loyalty; tier2Enabled: boolean }>()

const max = computed(() => Math.max(...props.loyalty.buckets.map((b) => b.visitors), 1))
</script>

<template>
  <section class="card p-5">
    <div class="flex items-baseline mb-3">
      <h3 class="text-sm font-medium text-[var(--ink-2)]">Loyalty <span class="font-normal text-[var(--ink-3)]">visits · consented</span></h3>
      <span v-if="loyalty.identified" class="ml-auto text-xs text-[var(--ink-3)]">{{ loyalty.identified.toLocaleString() }} visitors</span>
    </div>

    <p v-if="!tier2Enabled" class="text-sm text-[var(--ink-3)]">
      Tier-2 tracking is off. Enable it in Settings, then call <code class="rounded bg-[var(--bg)] px-1">melytics.consent(true)</code> from your consent banner.
    </p>
    <p v-else-if="!loyalty.identified" class="text-sm text-[var(--ink-3)]">
      No consented visitors yet — data appears once visitors accept via <code class="rounded bg-[var(--bg)] px-1">melytics.consent(true)</code>.
    </p>

    <div v-else>
      <div class="mb-3 flex items-baseline gap-2">
        <span class="text-2xl font-semibold tabular-nums">{{ loyalty.avg }}</span>
        <span class="text-sm text-[var(--ink-3)]">avg visits per visitor</span>
      </div>
      <div class="space-y-1.5">
        <div v-for="b in loyalty.buckets" :key="b.label" class="flex items-center gap-2 text-xs tabular-nums">
          <span class="w-12 shrink-0 text-[var(--ink-2)]">{{ b.label }}</span>
          <div class="h-4 flex-1 overflow-hidden rounded-md bg-[var(--bg)]">
            <div class="h-full rounded-md bg-[var(--accent-soft)]" :style="{ width: (b.visitors / max) * 100 + '%' }" />
          </div>
          <span class="w-8 shrink-0 text-right text-[var(--ink-3)]">{{ b.visitors }}</span>
        </div>
      </div>
    </div>
  </section>
</template>
