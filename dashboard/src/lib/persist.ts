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

/**
 * A persisted ref scoped to the current site: stored under `key:<siteId>`,
 * reloaded whenever `siteId` changes. Reads fall back to the legacy global
 * `key` so pre-existing (single-site era) values carry over per site.
 */
export function useSiteScopedRef<T>(
  key: string,
  siteId: Ref<number | undefined>,
  parse: (raw: string | null) => T,
  json = false
): Ref<T> {
  const keyFor = (id: number | undefined) => (id ? `${key}:${id}` : key)
  const read = (id: number | undefined): T => {
    try {
      return parse(localStorage.getItem(keyFor(id)) ?? localStorage.getItem(key))
    } catch {
      return parse(null)
    }
  }
  const r = ref(read(siteId.value)) as Ref<T>
  watch(siteId, (id) => {
    r.value = read(id)
  })
  watch(
    r,
    (v) => {
      try {
        if (v === null || v === undefined) localStorage.removeItem(keyFor(siteId.value))
        else localStorage.setItem(keyFor(siteId.value), json ? JSON.stringify(v) : String(v))
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
