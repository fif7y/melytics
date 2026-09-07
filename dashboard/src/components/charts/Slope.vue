<script setup lang="ts">
import { computed } from 'vue'
import { useChartTip, fmtInt } from '../../lib/useChartTip'

/** This period vs the previous one, one line per value. Up = accent, down = grey. */
const props = defineProps<{ rows: { key: string; label: string; icon?: string; now: number; before: number }[]; fromLabel: string; toLabel: string; selected?: string | null; clickable?: boolean }>()
const emit = defineEmits<{ select: [key: string] }>()
const { tip, show, hide, tipStyle } = useChartTip()

const W = 400, X0 = 64, X1 = 236
const lines = computed(() => {
  const rows = props.rows.slice().sort((a, b) => b.now - a.now).slice(0, 8)
  const H = Math.max(150, rows.length * 26)
  const max = Math.max(1, ...rows.flatMap((r) => [r.now, r.before]))
  const y = (v: number) => H - 8 - (v / max) * (H - 30)
  const ly = rows.map((r) => y(r.now))
  for (let i = 1; i < ly.length; i++) if (ly[i] - ly[i - 1] < 13) ly[i] = ly[i - 1] + 13
  const la = rows.map((r) => y(r.before)).sort((a, b) => a - b)
  const byBefore = rows.map((r, i) => ({ i, y: y(r.before) })).sort((a, b) => a.y - b.y)
  for (let i = 1; i < la.length; i++) if (la[i] - la[i - 1] < 13) la[i] = la[i - 1] + 13
  for (let i = la.length - 1; i >= 0; i--) if (la[i] > H - 4) la[i] = H - 4
  for (let i = la.length - 2; i >= 0; i--) if (la[i + 1] - la[i] < 13) la[i] = la[i + 1] - 13
  for (let i = ly.length - 1; i >= 0; i--) if (ly[i] > H - 4) ly[i] = H - 4
  for (let i = ly.length - 2; i >= 0; i--) if (ly[i + 1] - ly[i] < 13) ly[i] = ly[i + 1] - 13
  const beforeLabelY = new Array<number>(rows.length)
  byBefore.forEach((b, k) => (beforeLabelY[b.i] = la[k]))
  return {
    H,
    rows: rows.map((r, i) => {
      const up = r.now >= r.before
      const d = r.before ? Math.round(((r.now - r.before) / r.before) * 100) : null
      return { ...r, up, d, y0: y(r.before), y1: y(r.now), ly: ly[i], la: beforeLabelY[i], color: up ? 'var(--accent)' : 'var(--compare)' }
    }),
  }
})
</script>

<template>
  <div>
    <svg class="chart" :viewBox="`0 0 ${W} ${lines.H + 4}`">
      <text :x="X0" y="9" text-anchor="middle">{{ fromLabel }}</text>
      <text :x="X1" y="9" text-anchor="middle">{{ toLabel }}</text>
      <g v-for="r in lines.rows" :key="r.key" :class="clickable ? 'cursor-pointer' : ''" :opacity="selected && selected !== r.key ? 0.4 : 1" @mousemove="show($event, `${r.label} · ${fmtInt(r.before)} → ${fmtInt(r.now)}${r.d === null ? '' : ` (${r.d >= 0 ? '+' : ''}${r.d}%)`}`)" @mouseleave="hide" @click="clickable && emit('select', r.key)">
        <line :x1="X0" :y1="r.y0" :x2="X1" :y2="r.y1" :stroke="r.color" :stroke-width="r.up ? 2 : 1.5" />
        <circle :cx="X0" :cy="r.y0" r="3.5" :fill="r.color" stroke="var(--surface)" stroke-width="2" />
        <circle :cx="X1" :cy="r.y1" r="3.5" :fill="r.color" stroke="var(--surface)" stroke-width="2" />
        <text :x="X0 - 9" :y="r.la + 4" text-anchor="end">{{ fmtInt(r.before) }}</text>
        <text :x="X1 + 9" :y="r.ly + 4" :style="{ fill: r.up ? 'var(--ink)' : 'var(--ink-3)', fontWeight: selected === r.key ? 600 : 400 }">
          {{ fmtInt(r.now) }}
          <tspan :fill="r.up ? 'var(--ink-2)' : 'var(--ink-3)'" dx="6">{{ r.icon ? r.icon + ' ' : '' }}{{ r.label.length > 22 ? r.label.slice(0, 21) + '…' : r.label }}</tspan>
        </text>
      </g>
    </svg>
    <div v-if="tip" class="chart-tip" :style="tipStyle()">{{ tip.text }}</div>
  </div>
</template>
