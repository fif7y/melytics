<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, setToken } from '../lib/api'

const router = useRouter()
const route = useRoute()

type Mode = 'signin' | 'register' | 'forgot' | 'reset'
const mode = ref<Mode>(route.path === '/reset' ? 'reset' : 'signin')

const name = ref('')
const email = ref((route.query.email as string) ?? '')
const password = ref('')
const error = ref('')
const notice = ref('')
const busy = ref(false)

const heading = computed(
  () =>
    ({
      signin: 'melytics',
      register: 'Create account',
      forgot: 'Reset password',
      reset: 'Choose a new password',
    })[mode.value]
)

function switchTo(m: Mode) {
  mode.value = m
  error.value = ''
  notice.value = ''
}

async function submit() {
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    if (mode.value === 'signin' || mode.value === 'register') {
      const res = await api<{ token: string }>(`/auth/${mode.value === 'signin' ? 'login' : 'register'}`, {
        method: 'POST',
        body: JSON.stringify(
          mode.value === 'signin'
            ? { email: email.value, password: password.value }
            : { name: name.value, email: email.value, password: password.value }
        ),
      })
      setToken(res.token)
      router.push('/')
    } else if (mode.value === 'forgot') {
      await api('/auth/forgot', { method: 'POST', body: JSON.stringify({ email: email.value }) })
      notice.value = 'If that address has an account, a reset link is on its way.'
    } else {
      await api('/auth/reset', {
        method: 'POST',
        body: JSON.stringify({ token: route.query.token, email: email.value, password: password.value }),
      })
      notice.value = 'Password updated — sign in with it.'
      mode.value = 'signin'
      password.value = ''
    }
  } catch (e) {
    error.value = e instanceof Error && e.message !== 'unauthenticated' ? e.message : 'Something went wrong'
    if (mode.value === 'signin' && error.value.startsWith('API')) error.value = 'Invalid credentials'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="min-h-full grid place-items-center p-6">
    <form class="w-full max-w-sm" @submit.prevent="submit">
      <h1 class="text-2xl font-semibold tracking-tight mb-8 text-center">{{ heading }}</h1>
      <div class="card p-6 space-y-4">
        <input
          v-if="mode === 'register'"
          v-model="name"
          type="text"
          required
          placeholder="Name"
          autocomplete="name"
          class="w-full rounded-lg px-3.5 py-2.5 bg-[var(--bg)] outline-none focus:ring-2 ring-[var(--accent)] placeholder:text-[var(--ink-3)]"
        />
        <input
          v-model="email"
          type="email"
          required
          placeholder="Email"
          autocomplete="email"
          class="w-full rounded-lg px-3.5 py-2.5 bg-[var(--bg)] outline-none focus:ring-2 ring-[var(--accent)] placeholder:text-[var(--ink-3)]"
        />
        <input
          v-if="mode !== 'forgot'"
          v-model="password"
          type="password"
          required
          :minlength="mode === 'signin' ? undefined : 8"
          :placeholder="mode === 'reset' ? 'New password' : 'Password'"
          :autocomplete="mode === 'signin' ? 'current-password' : 'new-password'"
          class="w-full rounded-lg px-3.5 py-2.5 bg-[var(--bg)] outline-none focus:ring-2 ring-[var(--accent)] placeholder:text-[var(--ink-3)]"
        />
        <button
          :disabled="busy"
          class="w-full rounded-lg py-2.5 font-medium text-white bg-[var(--accent)] disabled:opacity-50"
        >
          {{ { signin: 'Sign in', register: 'Create account', forgot: 'Send reset link', reset: 'Set password' }[mode] }}
        </button>
        <p v-if="error" class="text-sm text-[var(--down)] text-center">{{ error }}</p>
        <p v-if="notice" class="text-sm text-[var(--ink-2)] text-center">{{ notice }}</p>
      </div>

      <div class="mt-4 flex justify-center gap-4 text-sm text-[var(--ink-3)]">
        <template v-if="mode === 'signin'">
          <button type="button" class="hover:text-[var(--ink)]" @click="switchTo('register')">Create account</button>
          <button type="button" class="hover:text-[var(--ink)]" @click="switchTo('forgot')">Forgot password?</button>
        </template>
        <button v-else type="button" class="hover:text-[var(--ink)]" @click="switchTo('signin')">Back to sign in</button>
      </div>
    </form>
  </div>
</template>
