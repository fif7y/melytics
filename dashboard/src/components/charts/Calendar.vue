<script setup lang="ts">
import { computed } from 'vue'
import { useChartTip, fmtInt } from '../../lib/useChartTip'
import type { Annotation, SeriesPoint } from '../../lib/api'

/**
 * One cell per day (weeks as columns, Monday on top) — the chart style that
 * turns a 365-point line into a year you can read. Hourly ranges become one
 * column per day with 24 hour rows. Notes mark their day with a dot.
 */
const props = defineProps<{ series: SeriesPoint[]; metric: 'visitors' | 'pageviews'; annotations?: Annotation[] }>()
const { tip, show, hide, tipStyle } = useChartTip()
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

type Cell = { key: string; col: number; row: number; v: number; label: string; note: string }
const model = computed(() => {
  const pts = props.series
  const hourly = (pts[0]?.t.length ?? 0) > 10
  const max = Math.max(1, ...pts.map((p) => p[props.metric]))
  const notes = new Map((props.annotations ?? []).map((a) => [a.day, a.text]))
  if (hourly) {
    const days = [...new Set(pts.map((p) => p.t.slice(0, 10)))]
    const cells: Cell[] = pts.map((p) => ({
      key: p.t,
      col: days.indexOf(p.t.slice(0, 10)),
      row: Number(p.t.slice(11, 13)),
      v: p[props.metric],
      label: `${p.t.slice(5, 10).replace('-', '/')} ${p.t.slice(11, 16)}`,
      note: '',
    }))
    return {
      hourly,
      rows: 24,
      cols: days.length,
      cells,
      max,
      colLabels: days.map((d, i) => ({ i, text: d.slice(5).replace('-', '/') })),
      rowLabels: [0, 6, 12, 18].map((h) => ({ i: h, text: `${h}h` })),
    }
  }
  if (!pts.length) return { hourly, rows: 7, cols: 0, cells: [] as Cell[], max, colLabels: [], rowLabels: [] }
  // up to two weeks: one cell per day in a row, labelled by weekday
  if (pts.length <= 14) {
    const cells: Cell[] = pts.map((p, i) => {
      const d = new Date(p.t + 'T00:00:00')
      return { key: p.t, col: i, row: 0, v: p[props.metric], label: `${MONTHS[d.getMonth()]} ${d.getDate()}`, note: notes.get(p.t) ?? '' }
    })
    return { hourly, rows: 1, cols: cells.length, cells, max, colLabels: cells.map((c, i) => ({ i, text: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][new Date(c.key + 'T00:00:00').getDay()] })), rowLabels: [] }
  }
  const offset = (new Date(pts[0].t + 'T00:00:00').getDay() + 6) % 7
  const cells: Cell[] = pts.map((p, i) => {
    const d = new Date(p.t + 'T00:00:00')
    return { key: p.t, col: Math.floor((i + offset) / 7), row: (i + offset) % 7, v: p[props.metric], label: `${MONTHS[d.getMonth()]} ${d.getDate()}`, note: notes.get(p.t) ?? '' }
  })
  const cols = cells[cells.length - 1].col + 1
  let colLabels: { i: number; text: string }[] = []
  if (cols <= 6) {
    colLabels = cells.filter((c) => c.row === 0).map((c) => ({ i: c.col, text: c.label }))
  } else {
    let last = -1
    cells.forEach((c, i) => {
      const m = new Date(pts[i].t + 'T00:00:00').getMonth()
      if (m !== last && (!colLabels.length || c.col - colLabels[colLabels.length - 1].i >= 3)) colLabels.push({ i: c.col, text: MONTHS[m] })
      last = m
    })
  }
  return { hourly, rows: 7, cols, cells, max, colLabels, rowLabels: [0, 2, 4, 6].map((r) => ({ i: r, text: ['Mon', '', 'Wed', '', 'Fri', '', 'Sun'][r] })) }
})
const W = 1000, L = 30
// short ranges get big cells (a week reads as a strip), long ones shrink to fit the width
const cs = computed(() => Math.min(model.value.rows === 1 ? 48 : model.value.cols <= 8 ? 34 : 18, Math.max(6, (W - L) / Math.max(1, model.value.cols))))
const height = computed(() => model.value.rows * cs.value + 18)
const mix = (v: number) => Math.round(6 + 94 * Math.pow(v / model.value.max, 0.6))
</script>

<template>
  <div>
    <svg class="chart" :viewBox="`0 0 ${L + model.cols * cs} ${height}`" preserveAspectRatio="xMinYMin meet" :style="{ maxHeight: model.hourly ? '420px' : '260px', maxWidth: model.rows === 1 ? (L + model.cols * cs) * 1.4 + 'px' : undefined }">
      <text v-for="c in model.colLabels" :key="'c' + c.i" :x="L + c.i * cs" y="10">{{ c.text }}</text>
      <text v-for="r in model.rowLabels" :key="'r' + r.i" x="0" :y="16 + r.i * cs + cs * 0.75">{{ r.text }}</text>
      <rect
        v-for="c in model.cells"
        :key="c.key"
        :x="L + c.col * cs + 1"
        :y="16 + c.row * cs + 1"
        :width="Math.max(1, cs - 2)"
        :height="Math.max(1, cs - 2)"
        rx="3"
        :fill="`color-mix(in srgb, var(--accent) ${mix(c.v)}%, var(--surface))`"
        @mousemove="show($event, `${c.label} · ${fmtInt(c.v)} ${metric}${c.note ? ' · ' + c.note : ''}`)"
        @mouseleave="hide"
      />
      <circle v-for="c in model.cells.filter((x) => x.note)" :key="'n' + c.key" :cx="L + c.col * cs + cs / 2" :cy="16 + c.row * cs + cs / 2" r="2.2" fill="var(--bg)" pointer-events="none" />
    </svg>
    <div v-if="tip" class="chart-tip" :style="tipStyle()">{{ tip.text }}</div>
  </div>
</template>
