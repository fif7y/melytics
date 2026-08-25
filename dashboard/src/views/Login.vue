<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, setToken } from '../lib/api'

const router = useRouter()
const email = ref('')
const password = ref('')
const error = ref('')
const busy = ref(false)

async function submit() {
  busy.value = true
  error.value = ''
  try {
    const res = await api<{ token: string }>('/auth/login', {
      method: 'POST',
      body: JSON.stringify({ email: email.value, password: password.value }),
    })
    setToken(res.token)
    router.push('/')
  } catch {
    error.value = 'Invalid credentials'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="min-h-full grid place-items-center p-6">
    <form class="w-full max-w-sm" @submit.prevent="submit">
      <h1 class="text-2xl font-semibold tracking-tight mb-8 text-center">melytics</h1>
      <div class="card p-6 space-y-4">
        <input
          v-model="email"
          type="email"
          required
          placeholder="Email"
          autocomplete="email"
          class="w-full rounded-lg px-3.5 py-2.5 bg-[var(--bg)] outline-none focus:ring-2 ring-[var(--accent)] placeholder:text-[var(--ink-3)]"
        />
        <input
          v-model="password"
          type="password"
          required
          placeholder="Password"
          autocomplete="current-password"
          class="w-full rounded-lg px-3.5 py-2.5 bg-[var(--bg)] outline-none focus:ring-2 ring-[var(--accent)] placeholder:text-[var(--ink-3)]"
        />
        <button
          :disabled="busy"
          class="w-full rounded-lg py-2.5 font-medium text-white bg-[var(--accent)] disabled:opacity-50"
        >
          Sign in
        </button>
        <p v-if="error" class="text-sm text-[var(--down)] text-center">{{ error }}</p>
      </div>
    </form>
  </div>
</template>
