<script setup lang="ts">
import { computed } from 'vue'
import type { Retention } from '../lib/api'

const props = defineProps<{ retention: Retention; tier2Enabled: boolean }>()

const pct = computed(() =>
  props.retention.identified ? Math.round((props.retention.returning / props.retention.identified) * 100) : 0
)
</script>

<template>
  <section class="card p-5">
    <div class="flex items-baseline mb-3">
      <h3 class="text-sm font-medium text-[var(--ink-2)]">Retention <span class="font-normal text-[var(--ink-3)]">consented</span></h3>
      <span v-if="retention.identified" class="ml-auto text-xs text-[var(--ink-3)]">{{ retention.identified.toLocaleString() }} visitors</span>
    </div>

    <p v-if="!tier2Enabled" class="text-sm text-[var(--ink-3)]">
      Tier-2 tracking is off. Enable it in Settings, then call <code class="rounded bg-[var(--bg)] px-1">melytics.consent(true)</code> from your consent banner.
    </p>
    <p v-else-if="!retention.identified" class="text-sm text-[var(--ink-3)]">
      No consented visitors yet — data appears once visitors accept via <code class="rounded bg-[var(--bg)] px-1">melytics.consent(true)</code>.
    </p>

    <div v-else>
      <div class="mb-1 flex items-baseline gap-2">
        <span class="text-2xl font-semibold tabular-nums">{{ pct }}%</span>
        <span class="text-sm text-[var(--ink-3)]">returning</span>
      </div>
      <div class="flex h-2 overflow-hidden rounded-full bg-[var(--bg)]">
        <div class="bg-[var(--accent)]" :style="{ width: pct + '%' }" />
      </div>
      <div class="mt-2 flex justify-between text-xs text-[var(--ink-3)] tabular-nums">
        <span>{{ retention.returning.toLocaleString() }} returning</span>
        <span>{{ retention.new.toLocaleString() }} new</span>
      </div>
    </div>
  </section>
</template>
