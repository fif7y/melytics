import { onBeforeUnmount } from 'vue'

// iOS Safari never fires HTML5 drag events for touch, so every drag-to-reorder
// surface pairs its dragstart/dragover/drop wiring with this long-press touch
// fallback. Items carry data-drag-key (and optionally data-drag-group); the
// finger position maps to a target via elementFromPoint.
//
// Long-press (250ms without moving) lifts the item; moving before that is a
// scroll and cancels cleanly. While lifted, touchmove is preventDefault'd so
// the page holds still, and the touchend that ends a drag is swallowed so it
// doesn't synthesize a click (the same elements are toggle/metric buttons).

// Coarse-pointer devices get the touch path; bind :draggable="!isCoarse" so
// native long-press drag (iOS 15+) never races the fallback.
export const isCoarse = typeof window !== 'undefined' && window.matchMedia('(pointer: coarse)').matches

export function useTouchReorder(cb: {
  start: (key: string, group?: string) => void
  over: (key: string | null) => void
  drop: (key: string, group?: string) => void
  end: () => void
}) {
  let timer: number | null = null
  let active = false
  let startX = 0
  let startY = 0
  let overKey: string | null = null
  let overGroup: string | undefined
  let source: HTMLElement | null = null
  let ghost: HTMLElement | null = null

  // The visible drag: a lifted clone of the card riding under the finger,
  // like the native drag ghost — elevation only, no outline
  function liftGhost() {
    if (!source) return
    const r = source.getBoundingClientRect()
    ghost = source.cloneNode(true) as HTMLElement
    ghost.style.cssText += `;position:fixed;left:${r.left}px;top:${r.top}px;width:${r.width}px;height:${r.height}px;margin:0;z-index:9999;pointer-events:none;opacity:0.92;box-shadow:0 12px 32px rgba(0,0,0,0.22);transform:scale(1);transition:transform 150ms cubic-bezier(0.23,1,0.32,1);will-change:transform;`
    document.body.appendChild(ghost)
    requestAnimationFrame(() => ghost && (ghost.style.transform = 'scale(1.04)'))
  }

  function moveGhost(x: number, y: number) {
    if (ghost) ghost.style.transform = `translate(${x - startX}px, ${y - startY}px) scale(1.04)`
  }

  function cleanup() {
    if (timer !== null) clearTimeout(timer)
    timer = null
    active = false
    overKey = null
    overGroup = undefined
    source = null
    ghost?.remove()
    ghost = null
    document.removeEventListener('touchmove', onMove)
    document.removeEventListener('touchend', onEnd)
    document.removeEventListener('touchcancel', onCancel)
  }

  function onMove(e: TouchEvent) {
    const t = e.touches[0]
    if (!active) {
      // moved before the long-press fired: it's a scroll, stand down
      if (Math.hypot(t.clientX - startX, t.clientY - startY) > 8) cleanup()
      return
    }
    e.preventDefault()
    moveGhost(t.clientX, t.clientY)
    const el = document.elementFromPoint(t.clientX, t.clientY)?.closest<HTMLElement>('[data-drag-key]')
    overKey = el?.dataset.dragKey ?? null
    overGroup = el?.dataset.dragGroup
    cb.over(overKey)
  }

  function onEnd(e: TouchEvent) {
    if (active) {
      e.preventDefault() // no synthetic click after a drag
      if (overKey) cb.drop(overKey, overGroup)
      cb.end()
      // settle the ghost out instead of popping it away
      const g = ghost
      if (g) {
        ghost = null
        g.style.transition += ',opacity 150ms ease'
        g.style.opacity = '0'
        setTimeout(() => g.remove(), 160)
      }
    }
    cleanup()
  }

  function onCancel() {
    if (active) cb.end()
    cleanup()
  }

  function touchStart(e: TouchEvent, key: string, group?: string) {
    if (e.touches.length !== 1) return
    cleanup()
    const t = e.touches[0]
    startX = t.clientX
    startY = t.clientY
    source = (e.currentTarget as HTMLElement | null) ?? null
    document.addEventListener('touchmove', onMove, { passive: false })
    document.addEventListener('touchend', onEnd, { passive: false })
    document.addEventListener('touchcancel', onCancel)
    timer = window.setTimeout(() => {
      timer = null
      active = true
      liftGhost()
      cb.start(key, group)
    }, 250)
  }

  onBeforeUnmount(cleanup)

  return { touchStart }
}
