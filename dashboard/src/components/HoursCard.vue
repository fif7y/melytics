<script setup lang="ts">
import { computed } from 'vue'
import type { Hours } from '../lib/api'
import LayoutMenu from './LayoutMenu.vue'
import Punchcard from './charts/Punchcard.vue'
import Clock from './charts/Clock.vue'

import { HOURS_LAYOUTS, type HoursLayout } from '../lib/layouts'

const props = defineProps<{ hours: Hours; layout: HoursLayout; timezone?: string }>()
const emit = defineEmits<{ 'update:layout': [l: HoursLayout] }>()

const empty = computed(() => !props.hours.grid.some((r) => r.some((v) => v > 0)))
// hourly mean across the range, for the clock's dotted ring
const avg = computed(() => Array.from({ length: 24 }, (_, h) => props.hours.grid.reduce((s, r) => s + r[h], 0) / Math.max(1, props.hours.days)))
const nowHour = computed(() => {
  try {
    return Number(new Intl.DateTimeFormat('en-GB', { hour: 'numeric', hour12: false, timeZone: props.timezone }).format(new Date())) % 24
  } catch {
    return new Date().getHours()
  }
})
</script>

<template>
  <section class="card p-5">
    <div class="mb-4 flex items-center gap-2">
      <h3 class="text-sm font-medium text-[var(--ink-2)]">Hours</h3>
      <span class="text-xs text-[var(--ink-3)]">{{ layout === 'clock' ? 'today vs average' : 'visitors by hour and weekday' }}</span>
      <LayoutMenu class="-my-1 ml-auto" :options="HOURS_LAYOUTS" :model-value="layout" title="Hours layout" @update:model-value="emit('update:layout', $event)" />
    </div>
    <p v-if="empty" class="text-sm text-[var(--ink-3)]">No data yet</p>
    <Clock v-else-if="layout === 'clock'" :today="hours.today" :avg="avg" :now-hour="nowHour" />
    <Punchcard v-else :grid="hours.grid" :days="hours.days" />
  </section>
</template>
