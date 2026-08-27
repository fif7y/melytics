import { computed, ref } from 'vue'
import { usePersistedRef, safeJson } from './persist'

/** Local calendar date, not toISOString (which is UTC and flips the day in the evening). */
export const isoDate = (d: Date) =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
export const todayIso = () => isoDate(new Date())

export const RANGES = [
  { label: 'Today', days: 1 },
  { label: '7d', days: 7 },
  { label: '30d', days: 30 },
  { label: '90d', days: 90 },
]

export const RANGE_PRESETS: { key: string; label: string; range: () => [Date, Date] }[] = [
  { key: 'month', label: 'This month', range: () => [new Date(new Date().getFullYear(), new Date().getMonth(), 1), new Date()] },
  { key: 'last-month', label: 'Last month', range: () => [new Date(new Date().getFullYear(), new Date().getMonth() - 1, 1), new Date(new Date().getFullYear(), new Date().getMonth(), 0)] },
  { key: 'ytd', label: 'Year to date', range: () => [new Date(new Date().getFullYear(), 0, 1), new Date()] },
  { key: '12mo', label: 'Last 12 months', range: () => [new Date(new Date().getFullYear() - 1, new Date().getMonth(), new Date().getDate()), new Date()] },
]

type CustomRange = { from: string; to: string } | { preset: string }

/**
 * Dashboard date range: preset day-counts plus a custom from/to picker.
 * A stored preset key ('ytd', 'month', …) is recomputed live so open-ended
 * ranges roll forward daily; explicit from/to dates stay fixed.
 */
export function useDateRange() {
  const rangeDays = usePersistedRef('melytics_range', (raw) => {
    const n = Number(raw)
    return [1, 7, 30, 90].includes(n) ? n : 30
  })

  const customRange = usePersistedRef<CustomRange | null>(
    'melytics_custom_range',
    (raw) => {
      const v = safeJson(raw) as any
      return (v?.from && v?.to) || v?.preset ? v : null
    },
    true
  )

  const pickingRange = ref(false)
  const pickFrom = ref('')
  const pickTo = ref('')
  const pickedPreset = ref<string | null>(null)

  const presetDates = (key: string): { from: string; to: string } | null => {
    const p = RANGE_PRESETS.find((x) => x.key === key)
    if (!p) return null
    const [f, t] = p.range()
    return { from: isoDate(f), to: isoDate(t) }
  }

  /** The resolved from/to for the active custom range, live for presets. */
  const resolvedCustom = computed(() => {
    if (!customRange.value) return null
    return 'preset' in customRange.value ? presetDates(customRange.value.preset) : customRange.value
  })

  function openRangePicker() {
    const r = resolvedCustom.value
    pickFrom.value = r?.from ?? todayIso()
    pickTo.value = r?.to ?? todayIso()
    pickedPreset.value = customRange.value && 'preset' in customRange.value ? customRange.value.preset : null
    pickingRange.value = true
  }

  function applyPresetChip(p: (typeof RANGE_PRESETS)[number]) {
    const d = presetDates(p.key)!
    pickFrom.value = d.from
    pickTo.value = d.to
    pickedPreset.value = p.key
  }

  function applyCustomRange() {
    if (!pickFrom.value || !pickTo.value) return
    // Fields untouched since the chip → store the preset itself, so it stays live
    const pd = pickedPreset.value ? presetDates(pickedPreset.value) : null
    const [from, to] = pickFrom.value <= pickTo.value ? [pickFrom.value, pickTo.value] : [pickTo.value, pickFrom.value]
    customRange.value = pd && pd.from === from && pd.to === to ? { preset: pickedPreset.value! } : { from, to }
    pickingRange.value = false
  }

  function setPresetRange(days: number) {
    customRange.value = null
    rangeDays.value = days
  }

  const customLabel = computed(() => {
    if (!customRange.value) return 'Custom'
    if ('preset' in customRange.value) return RANGE_PRESETS.find((p) => p.key === (customRange.value as { preset: string }).preset)?.label ?? 'Custom'
    const f = (s: string) => new Date(s + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
    return `${f(customRange.value.from)} – ${f(customRange.value.to)}`
  })

  function rangeParams() {
    if (resolvedCustom.value) return `from=${resolvedCustom.value.from}&to=${resolvedCustom.value.to}`
    const to = new Date()
    const from = new Date(Date.now() - (rangeDays.value - 1) * 86400_000)
    return `from=${isoDate(from)}&to=${isoDate(to)}`
  }

  return {
    rangeDays,
    customRange,
    pickingRange,
    pickFrom,
    pickTo,
    resolvedCustom,
    openRangePicker,
    applyPresetChip,
    applyCustomRange,
    setPresetRange,
    customLabel,
    rangeParams,
  }
}
