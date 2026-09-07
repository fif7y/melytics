<script setup lang="ts" generic="K extends string">
import { ref, onBeforeUnmount, onMounted } from 'vue'

/**
 * The per-card layout chooser (Funnels, Vitals and the chart style already use
 * this exact menu): a quiet lines glyph, a surface popover, the chosen layout
 * in accent. One recipe so every card's menu is the same menu.
 */
/**
 * `span` (1–3 grid columns) adds a width row at the bottom of the menu: three
 * glyphs, the chosen one ring-selected. It is the touch route to resizing; on
 * desktop the card's edge handle does the same thing directly.
 */
defineProps<{ options: readonly { key: K; label: string }[]; modelValue: K; title?: string; align?: 'left' | 'right'; span?: 1 | 2 | 3; minSpan?: 1 | 2 | 3 }>()
const emit = defineEmits<{ 'update:modelValue': [k: K]; 'update:span': [n: 1 | 2 | 3] }>()
const SPANS = [1, 2, 3] as const
const open = ref(false)
const root = ref<HTMLElement>()
function pick(k: K) {
  emit('update:modelValue', k)
  open.value = false
}
function onDoc(e: MouseEvent) {
  if (open.value && root.value && !root.value.contains(e.target as Node)) open.value = false
}
onMounted(() => document.addEventListener('click', onDoc))
onBeforeUnmount(() => document.removeEventListener('click', onDoc))
</script>

<template>
  <div ref="root" class="relative">
    <button
      class="flex h-7 w-7 items-center justify-center rounded-md text-[var(--ink-3)] hover:bg-[var(--bg)] hover:text-[var(--ink)]"
      :title="title ?? 'Layout'"
      :aria-label="title ?? 'Choose layout'"
      @click="open = !open"
    >
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
        <path d="M4 6h16M4 12h10M4 18h5" />
      </svg>
    </button>
    <div v-if="open" class="absolute top-full z-20 mt-1 w-36 rounded-xl bg-[var(--surface)] py-1 shadow-xl" :class="align === 'left' ? 'left-0' : 'right-0'">
      <button
        v-for="o in options"
        :key="o.key"
        class="flex w-full items-center px-3 py-1.5 text-left text-sm hover:bg-[var(--bg)]"
        :class="modelValue === o.key ? 'text-[var(--accent)] font-medium' : ''"
        @click="pick(o.key)"
      >
        {{ o.label }}
      </button>
      <div v-if="span" class="mx-2 flex items-center gap-1 pt-1.5" :class="options.length ? 'mt-1 border-t border-[var(--grid)]' : ''" role="group" aria-label="Card width">
        <span class="mr-auto pl-1 text-xs text-[var(--ink-3)]">Width</span>
        <button
          v-for="n in SPANS"
          :key="n"
          class="flex h-6 items-center justify-center gap-px rounded-md px-1.5 disabled:opacity-30"
          :class="span === n ? 'bg-[var(--accent-soft)] ring-1 ring-[var(--accent)]' : 'hover:bg-[var(--bg)]'"
          :disabled="n < (minSpan ?? 1)"
          :title="`${n} column${n > 1 ? 's' : ''}`"
          :aria-pressed="span === n"
          @click="emit('update:span', n)"
        >
          <i v-for="k in n" :key="k" class="block h-3 w-1 rounded-[2px]" :style="{ background: span === n ? 'var(--accent)' : 'var(--ink-3)' }" />
        </button>
      </div>
    </div>
  </div>
</template>
