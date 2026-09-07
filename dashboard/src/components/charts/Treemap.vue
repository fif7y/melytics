<script setup lang="ts">
import { computed } from 'vue'
import { useChartTip, fmtInt, pct, SPAN_W, type Span } from '../../lib/useChartTip'

/**
 * Pages grouped by first path segment → one column per section (width ∝
 * section views), rows inside (height ∝ page views). Section tint steps down
 * from the accent; pages step down inside their section.
 */
const props = defineProps<{ rows: { value: string; pageviews: number }[]; selected?: string | null; clickable?: boolean; span?: Span }>()
const emit = defineEmits<{ select: [key: string] }>()
const { tip, show, hide, tipStyle } = useChartTip()

const H = 200, G = 2
const W = computed(() => SPAN_W[props.span ?? 1])
const cells = computed(() => {
  const total = props.rows.reduce((s, r) => s + r.pageviews, 0)
  const groups = new Map<string, { value: string; pageviews: number }[]>()
  for (const r of props.rows) {
    const seg = r.value.split('/').filter(Boolean)[0]
    groups.get(seg ? `/${seg}` : '/')?.push(r) ?? groups.set(seg ? `/${seg}` : '/', [r])
  }
  const secs = [...groups.entries()]
    .map(([name, pages]) => ({ name, pages: pages.slice().sort((a, b) => b.pageviews - a.pageviews), sum: pages.reduce((s, p) => s + p.pageviews, 0) }))
    .sort((a, b) => b.sum - a.sum)
  const out: { key: string; x: number; y: number; w: number; h: number; mix: number; value: number; label: string; share: number }[] = []
  let x = 0
  secs.forEach((sec, si) => {
    const w = total ? (sec.sum / total) * W.value : 0
    let y = 0
    sec.pages.forEach((p, pi) => {
      const h = sec.sum ? (p.pageviews / sec.sum) * H : 0
      const mix = Math.max(10, 96 - si * 22 - pi * 12)
      out.push({ key: p.value, x, y, w, h, mix, value: p.pageviews, label: p.value, share: pct(p.pageviews, total) })
      y += h
    })
    x += w
  })
  return out
})
</script>

<template>
  <div>
    <svg class="chart" :viewBox="`0 0 ${W} ${H}`">
      <g v-for="c in cells" :key="c.key" :class="clickable ? 'cursor-pointer' : ''" :opacity="selected && selected !== c.key ? 0.45 : 1" @mousemove="show($event, `${c.label} · ${fmtInt(c.value)} · ${c.share}%`)" @mouseleave="hide" @click="clickable && emit('select', c.key)">
        <rect :x="c.x + G / 2" :y="c.y + G / 2" :width="Math.max(0, c.w - G)" :height="Math.max(0, c.h - G)" rx="5" :fill="`color-mix(in srgb, var(--accent) ${c.mix}%, var(--surface))`" />
        <text v-if="c.w > 64 && c.h > 26" :x="c.x + 9" :y="c.y + 17" :style="{ fill: c.mix > 62 ? 'var(--bg)' : 'var(--ink)', fontWeight: 500 }">
          {{ c.label.length > Math.floor(c.w / 7) ? c.label.slice(0, Math.max(3, Math.floor(c.w / 7) - 1)) + '…' : c.label }}
        </text>
        <text v-if="c.w > 64 && c.h > 42" :x="c.x + 9" :y="c.y + 32" :style="{ fill: c.mix > 62 ? 'var(--bg)' : 'var(--ink-3)', opacity: 0.85 }">{{ fmtInt(c.value) }}</text>
      </g>
    </svg>
    <div v-if="tip" class="chart-tip" :style="tipStyle()">{{ tip.text }}</div>
  </div>
</template>
