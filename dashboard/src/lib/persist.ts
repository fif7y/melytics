import { ref, watch, type Ref } from 'vue'

/**
 * A ref persisted to localStorage. `parse` maps the stored string (null when
 * absent) to a value — return the default for anything invalid. JSON values
 * round-trip via JSON.stringify; strings are stored raw.
 */
export function usePersistedRef<T>(key: string, parse: (raw: string | null) => T, json = false): Ref<T> {
  let initial: T
  try {
    initial = parse(localStorage.getItem(key))
  } catch {
    initial = parse(null)
  }
  const r = ref(initial) as Ref<T>
  watch(
    r,
    (v) => {
      try {
        if (v === null || v === undefined) localStorage.removeItem(key)
        else localStorage.setItem(key, json ? JSON.stringify(v) : String(v))
      } catch {}
    },
    { deep: true }
  )
  return r
}

/** parse helper: JSON.parse that never throws, null on failure. */
export function safeJson(raw: string | null): unknown {
  try {
    return raw === null ? null : JSON.parse(raw)
  } catch {
    return null
  }
}
