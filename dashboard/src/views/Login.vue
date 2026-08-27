<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, setToken } from '../lib/api'

const router = useRouter()
const route = useRoute()

type Mode = 'signin' | 'register' | 'forgot' | 'reset'
const mode = ref<Mode>(route.path === '/reset' ? 'reset' : 'signin')

// What this instance offers (registration open? Google configured?)
const features = ref({ registration: false, google: false })
onMounted(async () => {
  // Returning from the Google OAuth callback: ?token= / ?google_error= in the hash query
  if (route.query.token && route.path === '/login') {
    setToken(route.query.token as string)
    router.replace('/')
    return
  }
  if (route.query.google_error) {
    error.value = route.query.google_error as string
    router.replace({ query: {} })
  }
  try {
    features.value = await api<{ registration: boolean; google: boolean }>('/auth/config')
  } catch {}
})

function googleSignIn() {
  window.location.href = `${apiBase()}/auth/google/redirect`
}
function apiBase() {
  return window.MELYTICS_API ?? '/api'
}

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
        <template v-if="features.google && (mode === 'signin' || mode === 'register')">
          <div class="flex items-center gap-3 text-xs text-[var(--ink-3)]">
            <span class="h-px flex-1 bg-[var(--compare)]" aria-hidden="true" />or<span class="h-px flex-1 bg-[var(--compare)]" aria-hidden="true" />
          </div>
          <button
            type="button"
            class="w-full rounded-lg py-2.5 font-medium bg-[var(--bg)] text-[var(--ink)] hover:ring-2 ring-[var(--accent)] flex items-center justify-center gap-2.5"
            @click="googleSignIn"
          >
            <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
              <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
              <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
              <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
              <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
            </svg>
            {{ mode === 'signin' ? 'Continue with Google' : 'Sign up with Google' }}
          </button>
        </template>
        <p v-if="error" class="text-sm text-[var(--down)] text-center">{{ error }}</p>
        <p v-if="notice" class="text-sm text-[var(--ink-2)] text-center">{{ notice }}</p>
      </div>

      <div class="mt-4 flex justify-center gap-4 text-sm text-[var(--ink-3)]">
        <template v-if="mode === 'signin'">
          <button v-if="features.registration" type="button" class="hover:text-[var(--ink)]" @click="switchTo('register')">Create account</button>
          <button type="button" class="hover:text-[var(--ink)]" @click="switchTo('forgot')">Forgot password?</button>
        </template>
        <button v-else type="button" class="hover:text-[var(--ink)]" @click="switchTo('signin')">Back to sign in</button>
      </div>
    </form>
  </div>
</template>
