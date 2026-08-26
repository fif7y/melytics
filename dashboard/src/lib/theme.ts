import { ref } from 'vue'

export type Theme = 'system' | 'light' | 'dark'

const KEY = 'melytics_theme'

const stored = (() => {
  try {
    return localStorage.getItem(KEY) as Theme | null
  } catch {
    return null
  }
})()

export const theme = ref<Theme>(stored === 'light' || stored === 'dark' ? stored : 'system')

export function applyTheme() {
  const el = document.documentElement
  if (theme.value === 'system') delete el.dataset.theme
  else el.dataset.theme = theme.value
}

export function effectiveTheme(): 'light' | 'dark' {
  if (theme.value !== 'system') return theme.value
  return matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

export function toggleTheme() {
  theme.value = effectiveTheme() === 'dark' ? 'light' : 'dark'
  try {
    localStorage.setItem(KEY, theme.value)
  } catch {}
  applyTheme()
}
