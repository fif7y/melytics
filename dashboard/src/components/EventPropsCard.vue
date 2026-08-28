<script setup lang="ts">
import { computed, ref, toRef, watch } from 'vue'
import { api, type EventPropKey, type EventPropsResult } from '../lib/api'
import { useSiteScopedRef } from '../lib/persist'

// Custom-event property explorer. Pick an event and one of its properties:
// a string prop shows a value distribution, a numeric prop an aggregate
// (sum/avg/count), and a numeric prop with a "by" group becomes revenue-by-
// segment. Self-fetches (the selectors only live here); props aren't rolled up.
const props = defineProps<{ siteId?: number; range: string; events: string[] }>()

const siteId = toRef(props, 'siteId')
const event = useSiteScopedRef<string>('melytics_ep_event', siteId, (r) => r ?? '')
const prop = useSiteScopedRef<string>('melytics_ep_prop', siteId, (r) => r ?? '')
const by = useSiteScopedRef<string>('melytics_ep_by', siteId, (r) => r ?? '')

const keys = ref<EventPropKey[]>([])
const result = ref<EventPropsResult | null>(null)
const loading = ref(false)

const selectedType = computed(() => keys.value.find((k) => k.key === prop.value)?.type)
const stringKeys = computed(() => keys.value.filter((k) => k.type === 'string' && k.key !== prop.value))

let keyGen = 0
async function loadKeys() {
  if (!props.siteId || !event.value) {
    keys.value = []
    return
  }
  const gen = ++keyGen
  try {
    const r = await api<{ keys: EventPropKey[] }>(
      `/sites/${props.siteId}/event-prop-keys?event=${encodeURIComponent(event.value)}&${props.range}`
    )
    if (gen !== keyGen) return
    keys.value = r.keys
    if (prop.value && !r.keys.some((k) => k.key === prop.value)) prop.value = ''
  } catch {
    if (gen === keyGen) keys.value = []
  }
}

let dataGen = 0
async function loadData() {
  if (!props.siteId || !event.value || !prop.value) {
    result.value = null
    return
  }
  const gen = ++dataGen
  loading.value = true
  const useBy = selectedType.value === 'number' && by.value ? `&by=${encodeURIComponent(by.value)}` : ''
  try {
    const r = await api<EventPropsResult>(
      `/sites/${props.siteId}/event-props?event=${encodeURIComponent(event.value)}&prop=${encodeURIComponent(prop.value)}${useBy}&${props.range}`
    )
    if (gen === dataGen) result.value = r
  } catch {
    if (gen === dataGen) result.value = null
  } finally {
    if (gen === dataGen) loading.value = false
  }
}

// Default the event to the first available once events load.
watch(
  () => props.events,
  (evs) => {
    if (!event.value && evs.length) event.value = evs[0]
  },
  { immediate: true }
)
watch([event, () => props.range, siteId], loadKeys, { immediate: true })
watch([event, prop, by, () => props.range, siteId], loadData, { immediate: true })
// A string prop can't be grouped — clear a stale "by".
watch(selectedType, (t) => {
  if (t !== 'number' && by.value) by.value = ''
})

const num = (n: number) => n.toLocaleString(undefined, { maximumFractionDigits: 2 })

// Bars share one scale per result (count for string, sum for grouped).
const rows = computed(() => (result.value && 'rows' in result.value ? result.value.rows : []))
const barMax = computed(() =>
  Math.max(
    1,
    ...rows.value.map((r: any) => (result.value?.type === 'numeric' ? r.sum : r.count))
  )
)
const metric = (r: any) => (result.value?.type === 'numeric' ? r.sum : r.count)
</script>

<template>
  <section class="card p-5">
    <h3 class="text-sm font-medium text-[var(--ink-2)] mb-4">Event properties</h3>

    <p v-if="!events.length" class="text-sm text-[var(--ink-3)]">
      No custom events yet — call <code class="text-xs">melytics.track('name', {"{ key: value }"})</code> to send some.
    </p>

    <template v-else>
      <div class="flex flex-wrap gap-2 mb-4">
        <select v-model="event" class="rounded-lg px-2.5 py-1.5 text-sm bg-[var(--bg)] outline-none focus:ring-2 ring-[var(--accent)] max-w-[45%]">
          <option v-for="e in events" :key="e" :value="e">{{ e }}</option>
        </select>
        <select v-model="prop" class="rounded-lg px-2.5 py-1.5 text-sm bg-[var(--bg)] outline-none focus:ring-2 ring-[var(--accent)]" :disabled="!keys.length">
          <option value="">{{ keys.length ? 'property…' : 'no properties' }}</option>
          <option v-for="k in keys" :key="k.key" :value="k.key">{{ k.key }}{{ k.type === 'number' ? ' (#)' : '' }}</option>
        </select>
        <select v-if="selectedType === 'number'" v-model="by" class="rounded-lg px-2.5 py-1.5 text-sm bg-[var(--bg)] outline-none focus:ring-2 ring-[var(--accent)]">
          <option value="">total</option>
          <option v-for="k in stringKeys" :key="k.key" :value="k.key">by {{ k.key }}</option>
        </select>
      </div>

      <p v-if="!prop" class="text-sm text-[var(--ink-3)]">Pick a property to break this event down.</p>

      <!-- Numeric prop, no group: headline aggregate -->
      <div v-else-if="result?.type === 'aggregate'" class="flex gap-6">
        <div><div class="text-xs text-[var(--ink-3)]">Sum</div><div class="text-lg font-medium tabular-nums">{{ num(result.sum) }}</div></div>
        <div><div class="text-xs text-[var(--ink-3)]">Average</div><div class="text-lg font-medium tabular-nums">{{ num(result.avg) }}</div></div>
        <div><div class="text-xs text-[var(--ink-3)]">Count</div><div class="text-lg font-medium tabular-nums">{{ result.count.toLocaleString() }}</div></div>
      </div>

      <!-- String distribution, or numeric grouped: bars -->
      <ul v-else-if="rows.length" class="space-y-1.5">
        <li
          v-for="row in rows"
          :key="row.value"
          class="group/row relative flex items-center gap-3 rounded-md px-2.5 py-1.5 overflow-hidden"
        >
          <span class="absolute inset-y-0 left-0 rounded-md bg-[var(--accent-soft)]" :style="{ width: (metric(row) / barMax) * 100 + '%' }" />
          <span class="relative flex-1 truncate text-sm">{{ row.value ?? '—' }}</span>
          <span v-if="result?.type === 'numeric'" class="relative w-16 text-right text-xs tabular-nums text-[var(--ink-3)] opacity-0 transition-opacity group-hover/row:opacity-100">
            avg {{ num((row as any).avg) }}
          </span>
          <span class="relative text-sm tabular-nums text-[var(--ink-2)]">{{ num(metric(row)) }}</span>
        </li>
      </ul>

      <p v-else-if="!loading" class="text-sm text-[var(--ink-3)]">No values for this property in range.</p>
    </template>
  </section>
</template>
