<script setup lang="ts">
import { computed } from 'vue'
import type { Duration } from '../lib/api'
import Histogram from './charts/Histogram.vue'

/** How long visits last — the shape behind the Avg. visit tile. */
const props = defineProps<{ duration: Duration; avg?: number | null }>()
const mmss = (s: number) => `${Math.floor(s / 60)}:${String(Math.round(s % 60)).padStart(2, '0')}`
const counts = computed(() => props.duration.buckets.map((b) => b.sessions))
const labels = computed(() => props.duration.buckets.map((b) => b.label))
</script>

<template>
  <section class="card p-5">
    <div class="mb-4 flex items-baseline gap-2">
      <h3 class="text-sm font-medium text-[var(--ink-2)]">Visit duration</h3>
      <span v-if="duration.sessions" class="text-xs tabular-nums text-[var(--ink-3)]">
        median {{ mmss(duration.median ?? 0) }}<template v-if="avg != null"> · avg {{ mmss(avg) }}</template>
      </span>
    </div>
    <p v-if="!duration.sessions" class="text-sm text-[var(--ink-3)]">No sessions yet</p>
    <Histogram v-else :counts="counts" :step="1" :labels="labels" unit="visits" />
  </section>
</template>
