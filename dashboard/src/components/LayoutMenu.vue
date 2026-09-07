<script setup lang="ts" generic="K extends string">
import { ref, onBeforeUnmount, onMounted } from 'vue'

/**
 * The per-card layout chooser (Funnels, Vitals and the chart style already use
 * this exact menu): a quiet lines glyph, a surface popover, the chosen layout
 * in accent. One recipe so every card's menu is the same menu.
 */
defineProps<{ options: readonly { key: K; label: string }[]; modelValue: K; title?: string; align?: 'left' | 'right' }>()
const emit = defineEmits<{ 'update:modelValue': [k: K] }>()
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
    </div>
  </div>
</template>
