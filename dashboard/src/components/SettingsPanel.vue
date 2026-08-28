<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, TransitionGroup } from 'vue'
import Toggle from './Toggle.vue'

export interface ModuleDef {
  key: string
  label: string
  tier2?: boolean
}

const props = defineProps<{ modules: ModuleDef[]; hidden: string[]; density: 'comfy' | 'compact'; tier2: boolean }>()
const emit = defineEmits<{
  toggle: [key: string]
  density: [d: 'comfy' | 'compact']
  tier2: [on: boolean]
  reorder: [from: string, to: string]
}>()

const open = ref(false)

// Mirror the dashboard layout exactly: goals/funnels are their own row
// (swappable pair); the grid shows only the modules that are actually on the
// view, in view order, so the panel stays a mini-map when things toggle off.
// Off modules drop into their own group below; tier-2 modules sit in a locked
// group while tier-2 tracking is disabled (they can't render anyway).
const rowModules = computed(() => props.modules.filter((m) => m.key === 'goals' || m.key === 'funnels'))
const gridModules = computed(() => props.modules.filter((m) => m.key !== 'goals' && m.key !== 'funnels'))
const isOn = (m: ModuleDef) => !props.hidden.includes(m.key)
// One grid, one TransitionGroup: actives first in view order (the mini-map),
// off modules sink to the tail — toggling FLIP-animates the card to its spot.
const sortedGrid = computed(() => {
  const eligible = gridModules.value.filter((m) => !m.tier2 || props.tier2)
  return [...eligible.filter(isOn), ...eligible.filter((m) => !isOn(m))]
})
const tier2Locked = computed(() => (props.tier2 ? [] : gridModules.value.filter((m) => m.tier2)))

// Drag-to-reorder, same order store as the dashboard grid. Drags stay within
// their group (goals/funnels row vs grid) so the layout never lies.
const dragKey = ref<string | null>(null)
const dragGroup = ref<'row' | 'base' | null>(null)
const overKey = ref<string | null>(null)

function startDrag(key: string, group: 'row' | 'base') {
  dragKey.value = key
  dragGroup.value = group
}
function dropOn(key: string, group: 'row' | 'base') {
  if (dragKey.value && dragGroup.value === group && dragKey.value !== key) emit('reorder', dragKey.value, key)
  dragKey.value = null
  dragGroup.value = null
  overKey.value = null
}

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') open.value = false
}
onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <button
    class="flex h-9 w-9 items-center justify-center rounded-lg text-[var(--ink-2)] hover:bg-[var(--surface)] hover:text-[var(--ink)]"
    title="Modules"
    aria-label="Modules"
    @click="open = true"
  >
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
      <rect x="3" y="3" width="7" height="7" rx="1.5" />
      <rect x="14" y="3" width="7" height="7" rx="1.5" />
      <rect x="3" y="14" width="7" height="7" rx="1.5" />
      <rect x="14" y="14" width="7" height="7" rx="1.5" />
    </svg>
  </button>

  <Teleport to="body">
    <Transition name="scrim">
      <div v-if="open" class="fixed inset-0 z-30 bg-black/25" @click="open = false" />
    </Transition>
    <Transition name="drawer">
      <aside
        v-if="open"
        class="fixed right-0 top-0 z-40 flex h-full w-80 max-w-[85vw] flex-col bg-[var(--surface)] shadow-2xl lg:w-[30rem]"
        role="dialog"
        aria-label="Modules"
      >
        <div class="flex items-center px-5 pt-5 pb-4">
          <h2 class="text-sm font-semibold">Modules</h2>
          <button
            class="ml-auto flex h-8 w-8 items-center justify-center rounded-lg text-[var(--ink-3)] hover:bg-[var(--bg)] hover:text-[var(--ink)]"
            title="Close"
            aria-label="Close modules panel"
            @click="open = false"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M18 6 6 18M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div class="flex-1 space-y-6 overflow-y-auto px-5 pb-5">
          <section>
            <div class="mb-2 text-xs font-medium uppercase tracking-wide text-[var(--ink-3)]">Activate &amp; reorder</div>
            <!-- Same shape as the dashboard: goals/funnels row, then the grid, same breakpoints -->
            <div class="mb-3 grid grid-cols-1 gap-x-2 gap-y-3 lg:grid-cols-2">
              <button
                v-for="m in rowModules"
                :key="m.key"
                draggable="true"
                class="module-card"
                :class="{
                  on: !props.hidden.includes(m.key),
                  'opacity-40': dragKey === m.key,
                  'ring-2 ring-[var(--accent)]': overKey === m.key && dragKey && dragKey !== m.key,
                }"
                :aria-pressed="!props.hidden.includes(m.key)"
                @click="emit('toggle', m.key)"
                @dragstart="startDrag(m.key, 'row')"
                @dragend=";(dragKey = null), (overKey = null)"
                @dragover.prevent="overKey = m.key"
                @dragleave="overKey === m.key && (overKey = null)"
                @drop.prevent="dropOn(m.key, 'row')"
              >
                <span class="truncate">{{ m.label }}</span>
                <span class="switch"><span class="knob" /></span>
              </button>
            </div>
            <TransitionGroup tag="div" name="mods" class="relative grid grid-cols-1 gap-x-2 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
              <button
                v-for="m in sortedGrid"
                :key="m.key"
                :draggable="isOn(m)"
                class="module-card"
                :class="{
                  on: isOn(m),
                  'opacity-40': dragKey === m.key,
                  'ring-2 ring-[var(--accent)]': overKey === m.key && dragKey && dragKey !== m.key,
                }"
                :aria-pressed="isOn(m)"
                @click="emit('toggle', m.key)"
                @dragstart="isOn(m) && startDrag(m.key, 'base')"
                @dragend=";(dragKey = null), (overKey = null)"
                @dragover.prevent="isOn(m) && (overKey = m.key)"
                @dragleave="overKey === m.key && (overKey = null)"
                @drop.prevent="isOn(m) && dropOn(m.key, 'base')"
              >
                <span class="truncate">{{ m.label }}</span>
                <span class="switch"><span class="knob" /></span>
              </button>
            </TransitionGroup>
            <p class="mt-2 px-1 text-xs text-[var(--ink-3)]">Tap to turn a module on or off — off modules sink to the end and aren't fetched at all. Drag to reorder; this is your dashboard's layout.</p>

            <template v-if="tier2Locked.length">
              <div class="mt-4 mb-2 px-1 text-xs text-[var(--ink-3)]">Needs Tier-2 tracking — enable it under Privacy</div>
              <div class="grid grid-cols-1 gap-x-2 gap-y-3 opacity-50 sm:grid-cols-2 lg:grid-cols-3">
                <button
                  v-for="m in tier2Locked"
                  :key="m.key"
                  class="module-card"
                  :class="{ on: !props.hidden.includes(m.key) }"
                  :aria-pressed="!props.hidden.includes(m.key)"
                  @click="emit('toggle', m.key)"
                >
                  <span class="truncate">{{ m.label }}</span>
                  <span class="switch"><span class="knob" /></span>
                </button>
              </div>
            </template>
          </section>

          <section>
            <div class="mb-2 text-xs font-medium uppercase tracking-wide text-[var(--ink-3)]">Density</div>
            <div class="flex gap-1 rounded-lg bg-[var(--bg)] p-1">
              <button
                v-for="d in (['comfy', 'compact'] as const)"
                :key="d"
                class="flex-1 rounded-md px-2 py-1.5 text-sm"
                :class="props.density === d ? 'bg-[var(--accent-soft)] font-medium text-[var(--accent)]' : 'text-[var(--ink-2)]'"
                @click="emit('density', d)"
              >
                {{ d === 'comfy' ? 'Comfortable' : 'Compact' }}
              </button>
            </div>
          </section>

          <section>
            <div class="mb-2 text-xs font-medium uppercase tracking-wide text-[var(--ink-3)]">Privacy</div>
            <Toggle label="Tier-2 tracking" :on="props.tier2" @change="(on) => emit('tier2', on)" />
            <p class="mt-1 px-2 text-xs text-[var(--ink-3)]">
              Standard tracking forgets visitors daily. Tier-2 remembers users who accept via a
              consent banner - <code class="rounded bg-[var(--bg)] px-1">melytics.consent(true)</code>,
              unlocking the audience modules. Everyone else stays anonymous.
            </p>
          </section>
        </div>
      </aside>
    </Transition>
  </Teleport>
</template>

<style scoped>
/* Mini cards echo the dashboard's de-boxed cards: fill + soft shadow, no borders.
   On = lifted card with accent dot; off = flat ghost fill, dimmed label. */
.module-card {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: stretch;
  justify-content: space-between;
  min-height: 4.25rem;
  text-align: left;
  border-radius: 12px;
  padding: 0.625rem 0.75rem;
  font-size: 13px;
  font-weight: 500;
  color: var(--ink-3);
  background: color-mix(in srgb, var(--bg) 45%, transparent);
  transition: background 150ms ease, color 150ms ease, box-shadow 150ms ease, transform 150ms cubic-bezier(0.23, 1, 0.32, 1);
}
.module-card:active {
  transform: scale(0.97);
}
.module-card.on {
  color: var(--ink);
  background: var(--bg);
  box-shadow: var(--shadow);
}
/* Tiny read-only switch — pure visual feedback, the whole card is the control.
   Dark track always; the knob carries the state color. */
.module-card .switch {
  position: relative;
  margin-left: auto;
  height: 8px;
  width: 15px;
  flex: none;
  border-radius: 9999px;
  background: color-mix(in srgb, var(--ink) 12%, transparent);
}
.module-card .knob {
  position: absolute;
  top: 1.5px;
  left: 0;
  height: 5px;
  width: 5px;
  border-radius: 9999px;
  background: var(--ink-3);
  opacity: 0.6;
  transform: translateX(1.5px);
  transition: transform 150ms cubic-bezier(0.23, 1, 0.32, 1), background 150ms ease, opacity 150ms ease;
}
.module-card.on .knob {
  background: var(--accent);
  opacity: 1;
  transform: translateX(8.5px);
}

/* FLIP move when cards reflow after a toggle or reorder; tier-2 unlocks fade in */
.mods-move,
.mods-enter-active,
.mods-leave-active {
  transition: transform 0.25s cubic-bezier(0.23, 1, 0.32, 1), opacity 0.2s ease;
}
.mods-enter-from,
.mods-leave-to {
  opacity: 0;
  transform: scale(0.95);
}
.mods-leave-active {
  position: absolute;
}

.drawer-enter-active,
.drawer-leave-active {
  transition: transform 0.22s cubic-bezier(0.22, 1, 0.36, 1);
}
.drawer-enter-from,
.drawer-leave-to {
  transform: translateX(100%);
}
.scrim-enter-active,
.scrim-leave-active {
  transition: opacity 0.22s ease;
}
.scrim-enter-from,
.scrim-leave-to {
  opacity: 0;
}
@media (prefers-reduced-motion: reduce) {
  .drawer-enter-active,
  .drawer-leave-active,
  .scrim-enter-active,
  .scrim-leave-active,
  .mods-move {
    transition: none;
  }
}
</style>
