<script setup lang="ts">
import type { Mix } from '../lib/api'
import LayoutMenu from './LayoutMenu.vue'
import StackedArea from './charts/StackedArea.vue'
import Bump from './charts/Bump.vue'

import { MIX_LAYOUTS, MIX_DIMS, type MixLayout, type MixDim } from '../lib/layouts'

import type { Span } from '../lib/useChartTip'

defineProps<{ mix: Mix; layout: MixLayout; dim: MixDim; span?: Span }>()
const emit = defineEmits<{ 'update:layout': [l: MixLayout]; 'update:dim': [d: MixDim]; 'update:span': [n: Span] }>()
</script>

<template>
  <section class="card p-5">
    <div class="mb-4 flex items-center gap-2">
      <h3 class="text-sm font-medium text-[var(--ink-2)]">
        <select
          :value="dim"
          class="-ml-1 cursor-pointer appearance-none rounded-md bg-transparent px-1 py-0.5 text-sm font-medium text-[var(--ink-2)] outline-none hover:bg-[var(--bg)] focus-visible:ring-2 ring-[var(--accent)]"
          title="Dimension"
          @change="emit('update:dim', ($event.target as HTMLSelectElement).value as MixDim)"
        >
          <option v-for="d in MIX_DIMS" :key="d.key" :value="d.key">{{ d.label }}</option>
        </select>
      </h3>
      <span class="text-xs text-[var(--ink-3)]">{{ layout === 'bump' ? 'rank over time' : 'share over time' }}</span>
      <LayoutMenu class="-my-1 ml-auto" :options="MIX_LAYOUTS" :model-value="layout" title="Mix layout" :span="span" @update:model-value="emit('update:layout', $event)" @update:span="emit('update:span', $event)" />
    </div>
    <p v-if="mix.buckets.length < 2 || !mix.series.length" class="text-sm text-[var(--ink-3)]">Needs at least two days of data</p>
    <Bump v-else-if="layout === 'bump'" :buckets="mix.buckets" :series="mix.series" :span="span" />
    <StackedArea v-else :buckets="mix.buckets" :series="mix.series" :other="mix.other" :span="span" />
  </section>
</template>
