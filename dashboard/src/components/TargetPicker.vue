<script setup lang="ts">
import { computed, ref } from 'vue'

// Combo box for goal targets: dropdown of the site's real pages/events with a
// type chip, free typing (wildcards included) for power users.
const props = defineProps<{
  modelValue: string
  targets?: { pages: string[]; events: string[] }
  placeholder?: string
}>()
const emit = defineEmits<{ 'update:modelValue': [v: string]; picked: [] }>()

const open = ref(false)
const hi = ref(-1)

const options = computed(() => {
  const all = [
    ...(props.targets?.events ?? []).map((v) => ({ value: v, kind: 'event' as const })),
    ...(props.targets?.pages ?? []).map((v) => ({ value: v, kind: 'page' as const })),
  ]
  const q = props.modelValue.trim().toLowerCase()
  return q ? all.filter((o) => o.value.toLowerCase().includes(q)) : all
})

function pick(v: string) {
  emit('update:modelValue', v)
  open.value = false
  hi.value = -1
  emit('picked')
}
function onKey(e: KeyboardEvent) {
  if (!open.value || !options.value.length) return
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    hi.value = (hi.value + 1) % options.value.length
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    hi.value = (hi.value - 1 + options.value.length) % options.value.length
  } else if (e.key === 'Enter' && hi.value >= 0) {
    e.preventDefault()
    pick(options.value[hi.value].value)
  } else if (e.key === 'Escape') {
    open.value = false
  }
}
</script>

<template>
  <div class="relative flex-1">
    <input
      :value="modelValue"
      :placeholder="placeholder"
      class="w-full rounded-lg px-3 py-1.5 text-sm bg-[var(--bg)] outline-none focus:ring-2 ring-[var(--accent)] placeholder:text-[var(--ink-3)]"
      @input="emit('update:modelValue', ($event.target as HTMLInputElement).value), (open = true), (hi = -1)"
      @focus="open = true"
      @blur="open = false"
      @keydown="onKey"
    />
    <div
      v-if="open && options.length"
      class="absolute left-0 right-0 top-full z-20 mt-1 max-h-56 overflow-y-auto rounded-xl bg-[var(--surface)] py-1 shadow-xl"
    >
      <button
        v-for="(o, i) in options"
        :key="o.kind + o.value"
        type="button"
        class="flex w-full items-center gap-3 px-3 py-1.5 text-left text-sm"
        :class="i === hi ? 'bg-[var(--accent-soft)]' : 'hover:bg-[var(--bg)]'"
        @mousedown.prevent="pick(o.value)"
        @mouseenter="hi = i"
      >
        <span class="truncate">{{ o.value }}</span>
        <span class="ml-auto shrink-0 rounded-full bg-[var(--bg)] px-2 py-0.5 text-[10px] text-[var(--ink-3)]">{{ o.kind }}</span>
      </button>
    </div>
  </div>
</template>
