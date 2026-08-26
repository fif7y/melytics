<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { api } from '../lib/api'

const props = defineProps<{ siteId: number; hasGoals?: boolean; targets?: { pages: string[]; events: string[] } }>()
const emit = defineEmits<{ close: []; created: [] }>()

const open = ref(false)
const step = ref(0)
const dir = ref<1 | -1>(1)
defineExpose({
  show() {
    open.value = true
    step.value = 0
    done.value = false
  },
})

// --- Step 1: goal templates. Path goals work with zero code; event goals need
// one melytics.track() line — the badge tells the user which is which.
interface GoalTemplate {
  id: string
  name: string
  desc: string
  event?: string
  path?: string
  code?: boolean
}
const GOAL_TEMPLATES: GoalTemplate[] = [
  { id: 'thanks', name: 'Reached a thank-you page', desc: 'Counts anyone landing on your confirmation page.', path: '/thanks*' },
  { id: 'contact', name: 'Visited the contact page', desc: 'A soft signal that someone wants to reach you.', path: '/contact*' },
  { id: 'pricing', name: 'Viewed pricing', desc: 'Purchase intent — they looked at what it costs.', path: '/pricing*' },
  { id: 'newsletter', name: 'Newsletter signup', desc: 'Fire it when the subscribe form succeeds.', event: 'newsletter', code: true },
  { id: 'signup', name: 'Account created', desc: 'Fire it when registration completes.', event: 'signup', code: true },
  { id: 'purchase', name: 'Purchase completed', desc: 'Fire it on your order-confirmation moment.', event: 'purchase', code: true },
]
const picked = ref<Record<string, boolean>>({})

// How many of the site's real pages a path pattern matches (slash-insensitive)
function pageMatches(pattern: string): number {
  const pages = props.targets?.pages ?? []
  if (!pages.length || !pattern.startsWith('/')) return 0
  const rx = new RegExp('^' + pattern.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '/?$')
  return pages.filter((p) => rx.test(p)).length
}
const patterns = ref<Record<string, string>>(Object.fromEntries(GOAL_TEMPLATES.map((t) => [t.id, t.path ?? t.event ?? ''])))
const pickedCount = computed(() => Object.values(picked.value).filter(Boolean).length)

// --- Step 2: one starter funnel (optional)
const FUNNEL_PRESETS = [
  { id: 'landing', name: 'Landing → Pricing → Converted', steps: ['/', '/pricing*', '/thanks*'] },
  { id: 'content', name: 'Article → About → Contact', steps: ['/blog*', '/about*', '/contact*'] },
]
const funnelOn = ref(false)
const funnelName = ref(FUNNEL_PRESETS[0].name)
const funnelSteps = ref<string[]>([...FUNNEL_PRESETS[0].steps])
function applyPreset(p: (typeof FUNNEL_PRESETS)[number]) {
  funnelOn.value = true
  funnelName.value = p.name
  funnelSteps.value = [...p.steps]
}

// --- Step 3: create
const busy = ref(false)
const done = ref(false)
const createdEvents = ref<string[]>([])
const toCreate = computed(() => GOAL_TEMPLATES.filter((t) => picked.value[t.id]))

async function createAll() {
  busy.value = true
  try {
    for (const t of toCreate.value) {
      const target = patterns.value[t.id]
      await api(`/sites/${props.siteId}/goals`, {
        method: 'POST',
        body: JSON.stringify({ name: t.name, [t.event ? 'event' : 'path_pattern']: target }),
      })
    }
    createdEvents.value = toCreate.value.filter((t) => t.event).map((t) => patterns.value[t.id])
    if (funnelOn.value && funnelSteps.value.filter((s) => s.trim()).length >= 2) {
      await api(`/sites/${props.siteId}/funnels`, {
        method: 'POST',
        body: JSON.stringify({
          name: funnelName.value,
          steps: funnelSteps.value.filter((s) => s.trim()).map((s) => ({ name: s, path_pattern: s })),
        }),
      })
    }
    done.value = true
    emit('created')
  } finally {
    busy.value = false
  }
}

function go(n: number) {
  dir.value = n > step.value ? 1 : -1
  step.value = n
}
function close() {
  open.value = false
  emit('close')
}
watch(open, (o) => {
  document.documentElement.style.overflow = o ? 'hidden' : ''
})

const STEPS = ['Welcome', 'Goals', 'Funnel', 'Create']
</script>

<template>
  <Teleport to="body">
    <Transition name="wiz">
      <div v-if="open" class="fixed inset-0 z-50 overflow-y-auto bg-[var(--bg)]" role="dialog" aria-label="Setup assistant" @keydown.esc="close">
        <datalist id="wiz-pages">
          <option v-for="p in props.targets?.pages ?? []" :key="p" :value="p" />
        </datalist>
        <!-- top rail: quiet progress, one exit -->
        <div class="sticky top-0 z-10 flex items-center gap-4 bg-[var(--bg)] px-6 py-4">
          <span class="text-sm font-semibold tracking-tight">melytics</span>
          <span class="text-xs text-[var(--ink-3)]">Setup assistant</span>
          <div class="ml-auto flex items-center gap-1.5">
            <span
              v-for="(s, i) in STEPS"
              :key="s"
              class="h-1.5 rounded-full transition-all duration-300"
              :class="i === step ? 'w-6 bg-[var(--accent)]' : 'w-1.5 bg-[var(--ink-3)] opacity-40'"
            />
          </div>
          <button
            class="flex h-8 w-8 items-center justify-center rounded-lg text-[var(--ink-3)] hover:bg-[var(--surface)] hover:text-[var(--ink)]"
            aria-label="Close assistant"
            @click="close"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
          </button>
        </div>

        <Transition :name="dir === 1 ? 'slide-l' : 'slide-r'" mode="out-in">
          <!-- ————— Step 0: welcome ————— -->
          <div v-if="step === 0" :key="0" class="mx-auto max-w-2xl px-6 pb-16 pt-10">
            <h1 class="wiz-rise text-4xl font-semibold leading-[1.08] tracking-tight sm:text-5xl">
              Measure what<br />actually matters.
            </h1>
            <p class="wiz-rise mt-5 max-w-md text-[15px] leading-relaxed text-[var(--ink-2)]" style="--d: 1">
              Pageviews tell you people came. <b class="font-medium text-[var(--ink)]">Goals</b> tell you they did the thing
              you built the site for — and <b class="font-medium text-[var(--ink)]">funnels</b> show where the rest gave up.
            </p>

            <div class="mt-10 grid gap-4 sm:grid-cols-2">
              <div class="wiz-rise card p-5" style="--d: 2">
                <div class="mb-3 text-xs font-medium uppercase tracking-wide text-[var(--ink-3)]">A goal</div>
                <div class="flex items-baseline gap-3 rounded-md bg-[var(--bg)] px-3 py-2 text-sm">
                  <span>Newsletter signup</span>
                  <span class="ml-auto font-semibold tabular-nums">38</span>
                  <span class="text-xs text-[var(--up)]">4.2%</span>
                </div>
                <p class="mt-3 text-xs leading-relaxed text-[var(--ink-3)]">One action, counted. A page they reach, or an event your site fires.</p>
              </div>
              <div class="wiz-rise card p-5" style="--d: 3">
                <div class="mb-3 text-xs font-medium uppercase tracking-wide text-[var(--ink-3)]">A funnel</div>
                <div class="space-y-1.5">
                  <div v-for="(f, i) in [['Landing', 100], ['Pricing', 46], ['Signed up', 12]]" :key="i" class="flex items-center gap-2 text-xs tabular-nums">
                    <span class="w-16 shrink-0 text-[var(--ink-2)]">{{ f[0] }}</span>
                    <div class="h-3.5 flex-1 overflow-hidden rounded-md bg-[var(--bg)]">
                      <div class="wiz-bar h-full rounded-md bg-[var(--accent-soft)]" :style="{ width: f[1] + '%', '--d': 4 + i }" />
                    </div>
                    <span class="w-7 text-right text-[var(--ink-3)]">{{ f[1] }}%</span>
                  </div>
                </div>
                <p class="mt-3 text-xs leading-relaxed text-[var(--ink-3)]">The same journey as steps — you see exactly where people drop off.</p>
              </div>
            </div>

            <button class="wiz-rise mt-10 rounded-xl bg-[var(--accent)] px-6 py-3 text-sm font-medium text-white transition-transform hover:scale-[1.02]" style="--d: 5" @click="go(1)">
              {{ props.hasGoals ? 'Set up new goals' : 'Set up my first goals' }}
            </button>
          </div>

          <!-- ————— Step 1: pick goals ————— -->
          <div v-else-if="step === 1" :key="1" class="mx-auto max-w-2xl px-6 pb-16 pt-10">
            <h1 class="wiz-rise text-3xl font-semibold tracking-tight sm:text-4xl">Pick your goals.</h1>
            <p class="wiz-rise mt-3 max-w-md text-[15px] text-[var(--ink-2)]" style="--d: 1">
              Start from the common ones — you can edit the page path to match your site. Add your own later in the Goals card.
            </p>

            <div class="mt-8 grid gap-3 sm:grid-cols-2">
              <button
                v-for="(t, i) in GOAL_TEMPLATES"
                :key="t.id"
                class="wiz-rise rounded-[14px] p-4 text-left transition-all duration-200"
                :class="picked[t.id] ? 'bg-[var(--accent-soft)] ring-2 ring-[var(--accent)] shadow-sm' : 'bg-[var(--surface)] hover:shadow-sm'"
                :style="{ '--d': 2 + i * 0.5 }"
                @click="picked[t.id] = !picked[t.id]"
              >
                <div class="flex items-center gap-2">
                  <span class="text-sm font-medium">{{ t.name }}</span>
                  <span
                    class="ml-auto shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium"
                    :class="t.code ? 'bg-[var(--bg)] text-[var(--ink-3)]' : 'bg-[var(--up)]/10 text-[var(--up)]'"
                  >{{ t.code ? '1 line of code' : 'no code' }}</span>
                </div>
                <p class="mt-1 text-xs leading-relaxed text-[var(--ink-3)]">{{ t.desc }}</p>
                <div v-if="picked[t.id]" class="mt-2.5" @click.stop>
                  <input
                    v-model="patterns[t.id]"
                    :list="t.event ? undefined : 'wiz-pages'"
                    class="w-full rounded-lg bg-[var(--bg)] px-2.5 py-1.5 font-mono text-xs outline-none focus:ring-2 ring-[var(--accent)]"
                    :aria-label="`Target for ${t.name}`"
                  />
                  <p v-if="!t.event && (targets?.pages.length ?? 0) > 0" class="mt-1.5 text-[11px]" :class="pageMatches(patterns[t.id]) ? 'text-[var(--up)]' : 'text-[var(--ink-3)]'">
                    {{ pageMatches(patterns[t.id]) ? `Matches ${pageMatches(patterns[t.id])} of your pages` : 'No matching page yet — pick one from the list' }}
                  </p>
                </div>
                <div v-else class="mt-2.5 inline-block rounded-md bg-[var(--bg)] px-2 py-1 font-mono text-[11px] text-[var(--ink-2)]">
                  {{ t.event ?? t.path }}
                </div>
              </button>
            </div>

            <div class="mt-10 flex items-center gap-3">
              <button class="rounded-xl px-4 py-2.5 text-sm text-[var(--ink-2)] hover:bg-[var(--surface)]" @click="go(0)">Back</button>
              <button
                class="rounded-xl bg-[var(--accent)] px-6 py-3 text-sm font-medium text-white transition-transform hover:scale-[1.02] disabled:opacity-40"
                :disabled="!pickedCount"
                @click="go(2)"
              >
                Continue{{ pickedCount ? ` with ${pickedCount}` : '' }}
              </button>
              <button v-if="!pickedCount" class="text-sm text-[var(--ink-3)] hover:text-[var(--ink)]" @click="go(2)">Skip goals</button>
            </div>
          </div>

          <!-- ————— Step 2: funnel ————— -->
          <div v-else-if="step === 2" :key="2" class="mx-auto max-w-2xl px-6 pb-16 pt-10">
            <h1 class="wiz-rise text-3xl font-semibold tracking-tight sm:text-4xl">Want a funnel?</h1>
            <p class="wiz-rise mt-3 max-w-md text-[15px] text-[var(--ink-2)]" style="--d: 1">
              A funnel is 2–8 steps a visitor moves through, in order. Pick a starting shape and bend the paths to your site — or skip it, funnels are easy to add later.
            </p>

            <div class="wiz-rise mt-8 flex flex-wrap gap-2" style="--d: 2">
              <button
                v-for="p in FUNNEL_PRESETS"
                :key="p.id"
                class="rounded-full px-4 py-2 text-sm transition-colors"
                :class="funnelOn && funnelName === p.name ? 'bg-[var(--accent)] text-white' : 'bg-[var(--surface)] text-[var(--ink-2)] hover:text-[var(--ink)]'"
                @click="applyPreset(p)"
              >
                {{ p.name }}
              </button>
              <button
                class="rounded-full px-4 py-2 text-sm transition-colors"
                :class="!funnelOn ? 'bg-[var(--accent)] text-white' : 'bg-[var(--surface)] text-[var(--ink-2)] hover:text-[var(--ink)]'"
                @click="funnelOn = false"
              >
                Skip for now
              </button>
            </div>

            <div v-if="funnelOn" class="wiz-rise card mt-6 p-5" style="--d: 3">
              <input
                v-model="funnelName"
                class="mb-4 w-full rounded-lg bg-[var(--bg)] px-3 py-2 text-sm font-medium outline-none focus:ring-2 ring-[var(--accent)]"
                aria-label="Funnel name"
              />
              <div v-for="(_, i) in funnelSteps" :key="i" class="mb-2 flex items-center gap-3">
                <span class="w-5 text-right text-xs tabular-nums text-[var(--ink-3)]">{{ i + 1 }}</span>
                <input
                  v-model="funnelSteps[i]"
                  list="wiz-pages"
                  class="flex-1 rounded-lg bg-[var(--bg)] px-3 py-2 font-mono text-xs outline-none focus:ring-2 ring-[var(--accent)]"
                  :aria-label="`Step ${i + 1} path`"
                />
                <button v-if="funnelSteps.length > 2" class="text-[var(--ink-3)] hover:text-[var(--down)]" :aria-label="`Remove step ${i + 1}`" @click="funnelSteps.splice(i, 1)">×</button>
              </div>
              <button v-if="funnelSteps.length < 8" class="mt-1 pl-8 text-sm text-[var(--accent)]" @click="funnelSteps.push('')">+ Add step</button>
              <p class="mt-3 pl-8 text-xs text-[var(--ink-3)]">Paths support wildcards — <code class="rounded bg-[var(--bg)] px-1">/blog*</code> matches every article.</p>
            </div>

            <div class="mt-10 flex items-center gap-3">
              <button class="rounded-xl px-4 py-2.5 text-sm text-[var(--ink-2)] hover:bg-[var(--surface)]" @click="go(1)">Back</button>
              <button class="rounded-xl bg-[var(--accent)] px-6 py-3 text-sm font-medium text-white transition-transform hover:scale-[1.02]" @click="go(3)">Review</button>
            </div>
          </div>

          <!-- ————— Step 3: review + create ————— -->
          <div v-else :key="3" class="mx-auto max-w-2xl px-6 pb-16 pt-10">
            <template v-if="!done">
              <h1 class="wiz-rise text-3xl font-semibold tracking-tight sm:text-4xl">Ready to create.</h1>
              <div class="wiz-rise card mt-8 p-5" style="--d: 1">
                <div v-if="toCreate.length" class="mb-2 text-xs font-medium uppercase tracking-wide text-[var(--ink-3)]">Goals</div>
                <div v-for="t in toCreate" :key="t.id" class="flex items-center gap-3 py-1.5 text-sm">
                  <span>{{ t.name }}</span>
                  <span class="ml-auto rounded-md bg-[var(--bg)] px-2 py-0.5 font-mono text-[11px] text-[var(--ink-2)]">{{ patterns[t.id] }}</span>
                </div>
                <template v-if="funnelOn">
                  <div class="mb-2 mt-4 text-xs font-medium uppercase tracking-wide text-[var(--ink-3)]">Funnel</div>
                  <div class="flex items-center gap-3 py-1.5 text-sm">
                    <span>{{ funnelName }}</span>
                    <span class="ml-auto font-mono text-[11px] text-[var(--ink-3)]">{{ funnelSteps.filter((s) => s.trim()).join(' → ') }}</span>
                  </div>
                </template>
                <p v-if="!toCreate.length && !funnelOn" class="text-sm text-[var(--ink-3)]">Nothing selected — go back and pick at least one goal, or close the assistant.</p>
              </div>
              <div class="mt-10 flex items-center gap-3">
                <button class="rounded-xl px-4 py-2.5 text-sm text-[var(--ink-2)] hover:bg-[var(--surface)]" @click="go(2)">Back</button>
                <button
                  v-if="toCreate.length || funnelOn"
                  :disabled="busy"
                  class="rounded-xl bg-[var(--accent)] px-6 py-3 text-sm font-medium text-white transition-transform hover:scale-[1.02] disabled:opacity-50"
                  @click="createAll"
                >
                  {{ busy ? 'Creating…' : 'Create everything' }}
                </button>
              </div>
            </template>

            <template v-else>
              <h1 class="wiz-rise text-3xl font-semibold tracking-tight sm:text-4xl">Done — you're measuring.</h1>
              <p class="wiz-rise mt-3 max-w-md text-[15px] text-[var(--ink-2)]" style="--d: 1">
                Your page-based goals count from the next visit, no changes needed. They live in the Goals card on the dashboard.
              </p>
              <div v-if="createdEvents.length" class="wiz-rise card mt-8 p-5" style="--d: 2">
                <div class="mb-2 text-xs font-medium uppercase tracking-wide text-[var(--ink-3)]">One last thing — event goals</div>
                <p class="mb-4 text-sm leading-relaxed text-[var(--ink-2)]">
                  The tracking snippet already gives every page a <code class="rounded bg-[var(--bg)] px-1 font-mono text-xs">melytics.track()</code>
                  function. Your job is one line: call it at the exact moment the thing happens. Two ways to do it:
                </p>

                <p class="mb-1.5 text-xs font-medium text-[var(--ink-2)]">Simplest — right on the button or form in your HTML:</p>
                <pre class="mb-4 overflow-x-auto rounded-lg bg-[var(--bg)] p-3 font-mono text-xs leading-relaxed text-[var(--ink-2)]"><code>&lt;form onsubmit="melytics.track('{{ createdEvents[0] }}')"&gt;
&lt;button onclick="melytics.track('{{ createdEvents[0] }}')"&gt;</code></pre>

                <p class="mb-1.5 text-xs font-medium text-[var(--ink-2)]">Better — in your JavaScript, after the action really succeeded:</p>
                <pre class="overflow-x-auto rounded-lg bg-[var(--bg)] p-3 font-mono text-xs leading-relaxed text-[var(--ink-2)]"><code>// e.g. once your signup request comes back OK
melytics.track('{{ createdEvents[0] }}')</code></pre>

                <p v-if="createdEvents.length > 1" class="mt-4 text-xs text-[var(--ink-3)]">
                  Same pattern for each of yours: {{ createdEvents.map((e) => `melytics.track('${e}')`).join(' · ') }}
                </p>
                <p class="mt-3 text-xs text-[var(--ink-3)]">The goal starts counting the first time the event fires — nothing else to configure.</p>
              </div>
              <button class="wiz-rise mt-10 rounded-xl bg-[var(--accent)] px-6 py-3 text-sm font-medium text-white transition-transform hover:scale-[1.02]" style="--d: 3" @click="close">
                Back to the dashboard
              </button>
            </template>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
/* Choreography: everything rises on an expo-out curve, staggered by --d */
.wiz-rise {
  animation: rise 0.6s cubic-bezier(0.16, 1, 0.3, 1) both;
  animation-delay: calc(var(--d, 0) * 80ms);
}
@keyframes rise {
  from {
    opacity: 0;
    transform: translateY(14px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
.wiz-bar {
  transform-origin: left;
  animation: grow 0.7s cubic-bezier(0.16, 1, 0.3, 1) both;
  animation-delay: calc(var(--d, 0) * 80ms);
}
@keyframes grow {
  from {
    transform: scaleX(0);
  }
  to {
    transform: scaleX(1);
  }
}
.wiz-enter-active,
.wiz-leave-active {
  transition: opacity 0.3s ease-out;
}
.wiz-enter-from,
.wiz-leave-to {
  opacity: 0;
}
.slide-l-enter-active,
.slide-r-enter-active {
  transition: opacity 0.35s cubic-bezier(0.16, 1, 0.3, 1), transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
}
.slide-l-leave-active,
.slide-r-leave-active {
  transition: opacity 0.15s ease-in, transform 0.15s ease-in;
}
.slide-l-enter-from {
  opacity: 0;
  transform: translateX(24px);
}
.slide-l-leave-to {
  opacity: 0;
  transform: translateX(-16px);
}
.slide-r-enter-from {
  opacity: 0;
  transform: translateX(-24px);
}
.slide-r-leave-to {
  opacity: 0;
  transform: translateX(16px);
}
@media (prefers-reduced-motion: reduce) {
  .wiz-rise,
  .wiz-bar {
    animation: none;
  }
  .slide-l-enter-active,
  .slide-r-enter-active,
  .slide-l-leave-active,
  .slide-r-leave-active,
  .wiz-enter-active,
  .wiz-leave-active {
    transition: none;
  }
}
</style>
