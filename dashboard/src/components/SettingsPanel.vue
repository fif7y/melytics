<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'

export interface ModuleDef {
  key: string
  label: string
}

const props = defineProps<{ modules: ModuleDef[]; hidden: string[]; density: 'comfy' | 'compact' }>()
const emit = defineEmits<{ toggle: [key: string]; density: [d: 'comfy' | 'compact']; signout: [] }>()

const open = ref(false)

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') open.value = false
}
onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <button
    class="flex h-9 w-9 items-center justify-center rounded-lg text-[var(--ink-2)] hover:bg-[var(--surface)] hover:text-[var(--ink)]"
    title="Settings"
    aria-label="Settings"
    @click="open = true"
  >
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="12" r="3" />
      <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
    </svg>
  </button>

  <Teleport to="body">
    <Transition name="scrim">
      <div v-if="open" class="fixed inset-0 z-30 bg-black/25" @click="open = false" />
    </Transition>
    <Transition name="drawer">
      <aside
        v-if="open"
        class="fixed right-0 top-0 z-40 flex h-full w-80 max-w-[85vw] flex-col bg-[var(--surface)] shadow-2xl"
        role="dialog"
        aria-label="Settings"
      >
        <div class="flex items-center px-5 pt-5 pb-4">
          <h2 class="text-sm font-semibold">Settings</h2>
          <button
            class="ml-auto flex h-8 w-8 items-center justify-center rounded-lg text-[var(--ink-3)] hover:bg-[var(--bg)] hover:text-[var(--ink)]"
            title="Close"
            aria-label="Close settings"
            @click="open = false"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M18 6 6 18M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div class="flex-1 space-y-6 overflow-y-auto px-5 pb-5">
          <section>
            <div class="mb-2 text-xs font-medium uppercase tracking-wide text-[var(--ink-3)]">Modules</div>
            <label
              v-for="m in props.modules"
              :key="m.key"
              class="flex cursor-pointer items-center gap-3 rounded-lg px-2 py-2 text-sm hover:bg-[var(--bg)]"
            >
              <input
                type="checkbox"
                class="accent-[var(--accent)]"
                :checked="!props.hidden.includes(m.key)"
                @change="emit('toggle', m.key)"
              />
              {{ m.label }}
            </label>
            <p class="mt-1 px-2 text-xs text-[var(--ink-3)]">Hidden modules aren't fetched at all.</p>
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
        </div>

        <div class="px-5 py-4">
          <button
            class="flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-sm text-[var(--ink-2)] hover:bg-[var(--bg)] hover:text-[var(--ink)]"
            @click="emit('signout')"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
              <path d="m16 17 5-5-5-5M21 12H9" />
            </svg>
            Sign out
          </button>
        </div>
      </aside>
    </Transition>
  </Teleport>
</template>

<style scoped>
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
  .scrim-leave-active {
    transition: none;
  }
}
</style>
