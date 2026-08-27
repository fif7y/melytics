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

export type Accent = 'blue' | 'violet' | 'teal' | 'amber' | 'rose'

export const ACCENTS: Accent[] = ['blue', 'violet', 'teal', 'amber', 'rose']

const ACCENT_KEY = 'melytics_accent'

const storedAccent = (() => {
  try {
    return localStorage.getItem(ACCENT_KEY) as Accent | null
  } catch {
    return null
  }
})()

export const accent = ref<Accent>(storedAccent && ACCENTS.includes(storedAccent) ? storedAccent : 'blue')

export function applyTheme() {
  const el = document.documentElement
  if (theme.value === 'system') delete el.dataset.theme
  else el.dataset.theme = theme.value
  if (accent.value === 'blue') delete el.dataset.accent
  else el.dataset.accent = accent.value
}

export function effectiveTheme(): 'light' | 'dark' {
  if (theme.value !== 'system') return theme.value
  return matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

export function setTheme(t: Theme) {
  theme.value = t
  try {
    t === 'system' ? localStorage.removeItem(KEY) : localStorage.setItem(KEY, t)
  } catch {}
  applyTheme()
}

export function setAccent(a: Accent) {
  accent.value = a
  try {
    a === 'blue' ? localStorage.removeItem(ACCENT_KEY) : localStorage.setItem(ACCENT_KEY, a)
  } catch {}
  applyTheme()
}

export function toggleTheme() {
  theme.value = effectiveTheme() === 'dark' ? 'light' : 'dark'
  try {
    localStorage.setItem(KEY, theme.value)
  } catch {}
  applyTheme()
}
