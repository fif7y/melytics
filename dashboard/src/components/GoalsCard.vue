<script setup lang="ts">
import { ref } from 'vue'
import { api } from '../lib/api'
import TargetPicker from './TargetPicker.vue'

export interface GoalRow {
  id: number
  name: string
  event: string | null
  path_pattern: string | null
  conversions: number
  rate: number
}

const props = defineProps<{ siteId: number; goals: GoalRow[]; targets?: { pages: string[]; events: string[] } }>()
const emit = defineEmits<{ changed: []; assist: [] }>()

const adding = ref(false)
const name = ref('')
const target = ref('')
const busy = ref(false)

// Picking a real target auto-names the goal (still editable)
function suggestName() {
  if (name.value || !target.value) return
  name.value = target.value.startsWith('/')
    ? `Visited ${target.value}`
    : target.value.charAt(0).toUpperCase() + target.value.slice(1)
}

async function add() {
  if (!name.value || !target.value) return
  busy.value = true
  const isPath = target.value.startsWith('/')
  try {
    await api(`/sites/${props.siteId}/goals`, {
      method: 'POST',
      body: JSON.stringify({
        name: name.value,
        [isPath ? 'path_pattern' : 'event']: target.value,
      }),
    })
    name.value = ''
    target.value = ''
    adding.value = false
    emit('changed')
  } finally {
    busy.value = false
  }
}

const editingId = ref<number | null>(null)
const editName = ref('')
const editTarget = ref('')

function startEdit(g: GoalRow) {
  editingId.value = g.id
  editName.value = g.name
  editTarget.value = g.event ?? g.path_pattern ?? ''
}

async function saveEdit() {
  if (!editName.value || !editTarget.value || editingId.value === null) return
  busy.value = true
  const isPath = editTarget.value.startsWith('/')
  try {
    await api(`/sites/${props.siteId}/goals/${editingId.value}`, {
      method: 'PATCH',
      body: JSON.stringify({ name: editName.value, [isPath ? 'path_pattern' : 'event']: editTarget.value }),
    })
    editingId.value = null
    emit('changed')
  } catch (e) {
    alert(e instanceof Error ? e.message : 'Could not update the goal')
  } finally {
    busy.value = false
  }
}

async function remove(id: number) {
  try {
    await api(`/sites/${props.siteId}/goals/${id}`, { method: 'DELETE' })
    emit('changed')
  } catch (e) {
    alert(e instanceof Error ? e.message : 'Could not delete the goal')
  }
}
</script>

<template>
  <section class="card p-5">
    <div class="flex items-center gap-3 mb-4">
      <h3 class="text-sm font-medium text-[var(--ink-2)]">Goals</h3>
      <button class="ml-auto text-sm text-[var(--ink-3)] hover:text-[var(--ink)]" title="Open the setup assistant" @click="emit('assist')">
        Assistant
      </button>
      <button
        class="text-sm text-[var(--accent)]"
        @click="adding = !adding"
      >
        {{ adding ? 'Cancel' : 'Add goal' }}
      </button>
    </div>

    <form v-if="adding" class="flex gap-2 mb-4" @submit.prevent="add">
      <TargetPicker v-model="target" :targets="targets" placeholder="Pick a page or event, or type your own" @picked="suggestName" />
      <input
        v-model="name"
        placeholder="Name"
        class="w-36 rounded-lg px-3 py-1.5 text-sm bg-[var(--bg)] outline-none focus:ring-2 ring-[var(--accent)] placeholder:text-[var(--ink-3)]"
      />
      <button :disabled="busy" class="rounded-lg px-3 py-1.5 text-sm text-white bg-[var(--accent)] disabled:opacity-50">Save</button>
    </form>

    <p v-if="!goals.length && !adding" class="text-sm text-[var(--ink-3)]">
      Track conversions: an event (<code>melytics.track('signup')</code>) or a page visit (<code>/thanks</code>).
      New to goals? <button class="text-[var(--accent)]" @click="emit('assist')">Open the setup assistant</button>.
    </p>

    <ul class="space-y-1.5">
      <li v-for="g in goals" :key="g.id" class="group flex items-baseline gap-3 rounded-md px-2.5 py-1.5">
        <form v-if="editingId === g.id" class="flex flex-1 gap-2" @submit.prevent="saveEdit">
          <input
            v-model="editName"
            placeholder="Name"
            class="w-28 rounded-lg px-3 py-1.5 text-sm bg-[var(--bg)] outline-none focus:ring-2 ring-[var(--accent)]"
          />
          <TargetPicker v-model="editTarget" :targets="targets" placeholder="Pick a page or event, or type your own" />
          <button :disabled="busy" class="rounded-lg px-3 py-1.5 text-sm text-white bg-[var(--accent)] disabled:opacity-50">Save</button>
          <button type="button" class="rounded-lg px-2 py-1.5 text-sm text-[var(--ink-3)]" @click="editingId = null">Cancel</button>
        </form>
        <template v-else>
          <span class="text-sm">{{ g.name }}</span>
          <span class="text-xs text-[var(--ink-3)]">{{ g.event ?? g.path_pattern }}</span>
          <span class="ml-auto text-sm tabular-nums">{{ g.conversions.toLocaleString() }}</span>
          <span class="text-sm tabular-nums text-[var(--ink-2)] w-14 text-right">{{ g.rate }}%</span>
          <button
            class="-my-1 flex h-7 w-7 items-center justify-center self-center rounded-md text-[var(--ink-3)] opacity-60 transition-opacity hover:bg-[var(--bg)] hover:text-[var(--ink)] group-hover:opacity-100"
            title="Edit goal"
            aria-label="Edit goal"
            @click="startEdit(g)"
          >
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z" />
            </svg>
          </button>
          <button
            class="-my-1 flex h-7 w-7 items-center justify-center self-center rounded-md text-[var(--ink-3)] opacity-60 transition-opacity hover:bg-[var(--bg)] hover:text-[var(--down)] group-hover:opacity-100"
            title="Delete goal"
            aria-label="Delete goal"
            @click="remove(g.id)"
          >
            ×
          </button>
        </template>
      </li>
    </ul>
  </section>
</template>
