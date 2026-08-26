<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'

export interface ModuleDef {
  key: string
  label: string
}

const props = defineProps<{ modules: ModuleDef[]; hidden: string[] }>()
const emit = defineEmits<{ toggle: [key: string] }>()

const open = ref(false)
const root = ref<HTMLElement>()

function onDocClick(e: MouseEvent) {
  if (open.value && root.value && !root.value.contains(e.target as Node)) open.value = false
}
onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))
</script>

<template>
  <div ref="root" class="relative">
    <button
      class="text-sm text-[var(--ink-3)] hover:text-[var(--ink)]"
      title="Choose which modules to show"
      @click="open = !open"
    >
      ⚙
    </button>

    <div v-if="open" class="absolute right-0 top-8 z-20 w-52 card p-3 shadow-lg">
      <div class="mb-2 text-xs font-medium text-[var(--ink-3)]">Modules</div>
      <label
        v-for="m in props.modules"
        :key="m.key"
        class="flex cursor-pointer items-center gap-2.5 rounded-md px-1.5 py-1.5 text-sm hover:bg-[var(--bg)]"
      >
        <input
          type="checkbox"
          class="accent-[var(--accent)]"
          :checked="!props.hidden.includes(m.key)"
          @change="emit('toggle', m.key)"
        />
        {{ m.label }}
      </label>
    </div>
  </div>
</template>
