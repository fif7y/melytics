/**
 * Layouts a breakdown can take. `list` is the classic bar list; the rest are
 * alternate forms of the same rows. `compare` needs the previous period's
 * rows and `trend` needs a per-bucket series per value — the parent fetches
 * those only for cards that ask (see Dashboard.load), so both layouts are
 * offered only when the parent says it can supply them.
 */
export const BREAKDOWN_LAYOUTS = [
  { key: 'list', label: 'List' },
  { key: 'donut', label: 'Donut' },
  { key: 'strip', label: 'Strip' },
  { key: 'treemap', label: 'Treemap' },
  { key: 'compare', label: 'Compare' },
  { key: 'trend', label: 'Trend' },
] as const
export type BreakdownLayout = (typeof BREAKDOWN_LAYOUTS)[number]['key']

/** Hours card: hour × weekday punchcard, or today's clock against the hourly average. */
export const HOURS_LAYOUTS = [
  { key: 'punchcard', label: 'Punchcard' },
  { key: 'clock', label: 'Clock' },
] as const
export type HoursLayout = (typeof HOURS_LAYOUTS)[number]['key']

/** Mix card: a dimension's share over time (100% stacked) or its rank per week (bump). */
export const MIX_LAYOUTS = [
  { key: 'stacked', label: 'Stacked' },
  { key: 'bump', label: 'Ranks' },
] as const
export type MixLayout = (typeof MIX_LAYOUTS)[number]['key']
export const MIX_DIMS = [
  { key: 'device', label: 'Devices' },
  { key: 'browser', label: 'Browsers' },
  { key: 'country', label: 'Countries' },
  { key: 'referrer', label: 'Referrers' },
  { key: 'page', label: 'Pages' },
] as const
export type MixDim = (typeof MIX_DIMS)[number]['key']

/** Live card: the page list, or the last minute as a pulse strip. */
export const LIVE_LAYOUTS = [
  { key: 'list', label: 'List' },
  { key: 'pulse', label: 'Pulse' },
] as const
export type LiveLayout = (typeof LIVE_LAYOUTS)[number]['key']
