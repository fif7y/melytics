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

export type Accent = 'blue' | 'violet' | 'teal' | 'green' | 'amber' | 'orange' | 'rose' | 'custom'

/** Named swatches, cool→warm; 'custom' renders as the user's own color. */
export const ACCENTS: Exclude<Accent, 'custom'>[] = ['blue', 'violet', 'teal', 'green', 'amber', 'orange', 'rose']

const ACCENT_KEY = 'melytics_accent'
const ACCENT_HEX_KEY = 'melytics_accent_hex'

// Accent is per-site: stored under `melytics_accent:<siteId>` once chosen,
// falling back to the legacy global key for sites without an explicit choice.
let accentScope: number | null = null
const accentKey = () => (accentScope ? `${ACCENT_KEY}:${accentScope}` : ACCENT_KEY)
const accentHexKey = () => (accentScope ? `${ACCENT_HEX_KEY}:${accentScope}` : ACCENT_HEX_KEY)

const readAccentHex = (): string | null => {
  try {
    const raw = localStorage.getItem(accentHexKey()) ?? localStorage.getItem(ACCENT_HEX_KEY)
    return raw && /^#[0-9a-f]{6}$/i.test(raw) ? raw : null
  } catch {
    return null
  }
}

const readAccent = (): Accent => {
  try {
    const raw = (localStorage.getItem(accentKey()) ?? localStorage.getItem(ACCENT_KEY)) as Accent | null
    if (raw === 'custom') return readAccentHex() ? 'custom' : 'blue'
    return raw && (ACCENTS as string[]).includes(raw) ? raw : 'blue'
  } catch {
    return 'blue'
  }
}

export const accent = ref<Accent>(readAccent())
export const accentHex = ref<string | null>(readAccentHex())

/** Point the accent at a site — reloads that site's stored accent. */
export function scopeAccent(siteId: number | undefined) {
  accentScope = siteId ?? null
  accentHex.value = readAccentHex()
  accent.value = readAccent()
  applyTheme()
}

export function applyTheme() {
  const el = document.documentElement
  if (theme.value === 'system') delete el.dataset.theme
  else el.dataset.theme = theme.value
  if (accent.value === 'custom' && accentHex.value) {
    // Custom color overrides the palette inline — same hex in both modes
    delete el.dataset.accent
    el.style.setProperty('--accent', accentHex.value)
    el.style.setProperty('--accent-soft', `color-mix(in srgb, ${accentHex.value} 14%, transparent)`)
  } else {
    el.style.removeProperty('--accent')
    el.style.removeProperty('--accent-soft')
    if (accent.value === 'blue') delete el.dataset.accent
    else el.dataset.accent = accent.value
  }
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
    // Site-scoped keys always store the choice explicitly (even blue) so a
    // legacy global accent can't bleed through; the global key keeps the old
    // remove-on-default behavior.
    if (accentScope) localStorage.setItem(accentKey(), a)
    else a === 'blue' ? localStorage.removeItem(ACCENT_KEY) : localStorage.setItem(ACCENT_KEY, a)
  } catch {}
  applyTheme()
}

/** Pick a custom accent color (hex like #1abc9c) for the current site. */
export function setAccentHex(hex: string) {
  accentHex.value = hex
  try {
    localStorage.setItem(accentHexKey(), hex)
  } catch {}
  setAccent('custom')
}

export function toggleTheme() {
  theme.value = effectiveTheme() === 'dark' ? 'light' : 'dark'
  try {
    localStorage.setItem(KEY, theme.value)
  } catch {}
  applyTheme()
}
