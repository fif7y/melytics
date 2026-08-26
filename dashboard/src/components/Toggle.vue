<script setup lang="ts">
defineProps<{ label: string; on: boolean; dim?: boolean }>()
const emit = defineEmits<{ change: [on: boolean] }>()
</script>

<template>
  <button
    type="button"
    role="switch"
    :aria-checked="on"
    class="flex w-full cursor-pointer items-center justify-between gap-3 rounded-lg px-2 py-2 text-left text-sm hover:bg-[var(--bg)]"
    :class="{ 'opacity-50': dim }"
    @click="emit('change', !on)"
  >
    <span>{{ label }}</span>
    <span
      class="relative h-[18px] w-8 shrink-0 rounded-full transition-colors duration-150"
      :class="on ? 'bg-[var(--accent)]' : 'bg-[var(--ink-3)] opacity-40'"
    >
      <span
        class="absolute left-0 top-[2px] h-3.5 w-3.5 rounded-full bg-white shadow-sm transition-transform duration-150"
        :class="on ? 'translate-x-[16px]' : 'translate-x-[2px]'"
      />
    </span>
  </button>
</template>
