<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { BreakdownRow } from '../lib/api'

const props = defineProps<{
  breakdowns: Record<string, BreakdownRow[]>
  visible: string[]
  selected: { dim: string; value: string } | null
}>()
const emit = defineEmits<{ select: [dim: string, value: string] }>()

const GROUPS = [
  { key: 'sources', title: 'Sources', dims: [{ key: 'referrer', label: 'Referrers' }, { key: 'utm_campaign', label: 'Campaigns' }] },
  { key: 'pages', title: 'Pages', dims: [{ key: 'page', label: 'Pages' }] },
  { key: 'audience', title: 'Audience', dims: [{ key: 'country', label: 'Countries' }, { key: 'device', label: 'Devices' }, { key: 'browser', label: 'Browsers' }] },
  { key: 'events', title: 'Events', dims: [{ key: 'event', label: 'Events' }] },
]

const groups = computed(() =>
  GROUPS.map((g) => ({ ...g, dims: g.dims.filter((d) => props.visible.includes(d.key)) })).filter((g) => g.dims.length)
)

const activeGroup = ref(groups.value[0]?.key ?? 'sources')
const dimByGroup = ref<Record<string, string>>({})

const group = computed(() => groups.value.find((g) => g.key === activeGroup.value) ?? groups.value[0])
const activeDim = computed(() => {
  const g = group.value
  if (!g) return null
  const picked = dimByGroup.value[g.key]
  return g.dims.find((d) => d.key === picked) ?? g.dims[0]
})

watch(groups, (gs) => {
  if (!gs.find((g) => g.key === activeGroup.value) && gs.length) activeGroup.value = gs[0].key
})

const rows = computed(() => (activeDim.value ? props.breakdowns[activeDim.value.key] ?? [] : []))
const total = computed(() => rows.value.reduce((s, r) => s + r.pageviews, 0))
const max = computed(() => Math.max(...rows.value.map((r) => r.pageviews), 1))

// Countries arrive as ISO codes; render flag + full name, filter still uses the raw code.
const regionNames = (() => {
  try {
    return new Intl.DisplayNames(['en'], { type: 'region' })
  } catch {
    return null
  }
})()
function display(row: BreakdownRow) {
  if (activeDim.value?.key !== 'country' || !/^[A-Za-z]{2}$/.test(row.value)) return { icon: '', label: row.value }
  const cc = row.value.toUpperCase()
  return {
    icon: cc.replace(/./g, (c) => String.fromCodePoint(127397 + c.charCodeAt(0))),
    label: regionNames?.of(cc) ?? cc,
  }
}
</script>

<template>
  <section class="card p-5">
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <div class="flex items-center gap-1 rounded-lg bg-[var(--bg)] p-1">
        <button
          v-for="g in groups"
          :key="g.key"
          class="rounded-md px-3 py-1 text-sm"
          :class="activeGroup === g.key ? 'bg-[var(--accent-soft)] font-medium text-[var(--accent)]' : 'text-[var(--ink-2)]'"
          @click="activeGroup = g.key"
        >
          {{ g.title }}
        </button>
      </div>

      <div v-if="group && group.dims.length > 1" class="ml-auto flex items-center gap-3">
        <button
          v-for="d in group.dims"
          :key="d.key"
          class="text-xs"
          :class="activeDim?.key === d.key ? 'font-medium text-[var(--ink)]' : 'text-[var(--ink-3)] hover:text-[var(--ink-2)]'"
          @click="dimByGroup[group.key] = d.key"
        >
          {{ d.label }}
        </button>
      </div>
    </div>

    <p v-if="!rows.length" class="text-sm text-[var(--ink-3)]">No data yet</p>
    <ul class="space-y-1.5">
      <li
        v-for="row in rows"
        :key="row.value"
        class="group relative flex items-center gap-3 rounded-md px-2.5 py-1.5 overflow-hidden cursor-pointer select-none hover:bg-[color-mix(in_srgb,var(--ink)_4%,transparent)]"
        :title="selected?.dim === activeDim?.key && selected?.value === row.value ? 'Clear filter' : `Filter dashboard by ${row.value}`"
        @click="activeDim && emit('select', activeDim.key, row.value)"
      >
        <span
          class="absolute inset-y-0 left-0 rounded-md bg-[var(--accent-soft)]"
          :style="{ width: (row.pageviews / max) * 100 + '%' }"
        />
        <span v-if="display(row).icon" class="relative">{{ display(row).icon }}</span>
        <span
          class="relative flex-1 truncate text-sm"
          :class="{ 'font-medium text-[var(--accent)]': selected?.dim === activeDim?.key && selected?.value === row.value }"
        >
          {{ display(row).label }}
        </span>
        <span class="relative w-10 text-right text-xs tabular-nums text-[var(--ink-3)] opacity-0 transition-opacity group-hover:opacity-100">
          {{ Math.round((row.pageviews / (total || 1)) * 100) }}%
        </span>
        <span class="relative text-sm tabular-nums text-[var(--ink-2)]">{{ row.pageviews.toLocaleString() }}</span>
      </li>
    </ul>
  </section>
</template>
