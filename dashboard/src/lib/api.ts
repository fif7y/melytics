// Runtime-configurable API base so one build mounts anywhere.
declare global {
  interface Window {
    MELYTICS_API?: string
  }
}

const base = window.MELYTICS_API ?? '/api'

export function token(): string | null {
  try {
    return localStorage.getItem('melytics_token')
  } catch {
    return null
  }
}

export function setToken(t: string | null) {
  try {
    t ? localStorage.setItem('melytics_token', t) : localStorage.removeItem('melytics_token')
  } catch {}
}

export async function api<T = unknown>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await fetch(base + path, {
    ...init,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token() ? { Authorization: `Bearer ${token()}` } : {}),
      ...(init.headers ?? {}),
    },
  })
  if (res.status === 401) {
    setToken(null)
    location.hash = '#/login'
    throw new Error('unauthenticated')
  }
  if (!res.ok) throw new Error(`API ${res.status}`)
  return res.json()
}

export interface SeriesPoint {
  t: string
  pageviews: number
  visitors: number
}
export interface Stats {
  series: SeriesPoint[]
  previous_series: SeriesPoint[]
  totals: { pageviews: number; visitors: number }
  previous_totals: { pageviews: number; visitors: number }
  range: { from: string; to: string; interval: 'hour' | 'day' }
}
export interface Site {
  id: number
  name: string
  domain: string
  key: string
}
export interface Annotation {
  id: number
  day: string
  text: string
}
export interface BreakdownRow {
  value: string
  pageviews: number
  visitors: number
}
