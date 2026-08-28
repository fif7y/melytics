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
  if (!res.ok) {
    // Surface the server's message (validation / gate errors) when it has one
    const msg = await res.json().then((b) => b?.message).catch(() => null)
    throw new Error(msg || `API ${res.status}`)
  }
  return res.json()
}

export interface SeriesPoint {
  t: string
  pageviews: number
  visitors: number
  sessions?: number
  bounces?: number
  duration_sum?: number
}
export interface Totals {
  pageviews: number
  visitors: number
  sessions?: number
  bounce_rate?: number | null
  avg_duration?: number | null
}
export interface Stats {
  series: SeriesPoint[]
  previous_series: SeriesPoint[]
  totals: Totals
  previous_totals: Totals
  range: { from: string; to: string; interval: 'hour' | 'day' }
}
export interface Site {
  id: number
  name: string
  domain: string
  key: string
  timezone: string
  tier2_enabled: boolean
  digest_enabled: boolean
  alerts_enabled: boolean
}
export interface Me {
  id: number
  name: string
  email: string
  verified: boolean
  cron_stale?: boolean
  cron_line?: string | null
  mail_off?: boolean
  is_admin?: boolean
  version?: string
  update?: { latest: string; url: string } | null
}
export interface Retention {
  identified: number
  new: number
  returning: number
}
export interface CohortRow {
  week: string
  size: number
  active: number[]
}
export interface Loyalty {
  identified: number
  avg: number
  buckets: { label: string; visitors: number }[]
}
export interface Attribution {
  identified: number
  channels: { channel: string; visitors: number }[]
}
export interface TimeToConvert {
  identified: number
  median_days: number
  median_sessions: number
  buckets: { label: string; visitors: number }[]
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

/** Blocked bot traffic: total dropped pageviews + top crawler names. */
export interface Bots {
  total: number
  names: { value: string; pageviews: number }[]
}
