import { ref } from 'vue'

/**
 * One hover tooltip per chart: call `show(event, text)` from a mark's
 * mousemove and `hide()` on leave; render `<div v-if="tip" class="chart-tip"
 * :style="tipStyle">{{ tip.text }}</div>` once in the component. Coarse
 * pointers get the same via touchstart on the mark.
 */
export function useChartTip() {
  const tip = ref<{ x: number; y: number; text: string } | null>(null)
  function show(e: MouseEvent | TouchEvent, text: string) {
    const p = 'touches' in e ? e.touches[0] : e
    if (!p) return
    tip.value = { x: p.clientX, y: p.clientY, text }
  }
  const hide = () => (tip.value = null)
  const tipStyle = () => (tip.value ? { left: tip.value.x + 'px', top: tip.value.y + 'px' } : {})
  return { tip, show, hide, tipStyle }
}

/** Series tints, in fixed order; index 4+ folds to grey (Other). */
export const TINTS = ['var(--t1)', 'var(--t2)', 'var(--t3)', 'var(--t4)', 'var(--t5)']
export const tint = (i: number) => (i < TINTS.length ? TINTS[i] : 'var(--compare)')

/** Card span (grid columns) → SVG viewBox width, so a wide card gets a wide chart, not a stretched one. */
export type Span = 1 | 2 | 3
export const SPAN_W: Record<Span, number> = { 1: 400, 2: 820, 3: 1240 }

export const fmtInt = (n: number) => Math.round(n).toLocaleString()
export const pct = (n: number, total: number) => (total ? Math.round((n / total) * 100) : 0)
