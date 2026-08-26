<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import uPlot from 'uplot'
import 'uplot/dist/uPlot.min.css'
import type { Annotation, SeriesPoint } from '../lib/api'

const props = defineProps<{
  series: SeriesPoint[]
  previous: SeriesPoint[]
  metric: 'visitors' | 'pageviews'
  annotations?: Annotation[]
}>()

const el = ref<HTMLDivElement>()
const tip = ref<HTMLDivElement>()
let chart: uPlot | null = null

function cssVar(name: string) {
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim()
}

function build() {
  if (!el.value) return
  chart?.destroy()

  // hourly points carry a time component ("YYYY-MM-DD HH:00:00")
  const hourly = (props.series[0]?.t.length ?? 0) > 10
  const xs = props.series.map((p) => new Date(p.t.replace(' ', 'T')).getTime() / 1000)
  const ys = props.series.map((p) => p[props.metric])
  // previous period aligned onto the current x axis (comparison overlay)
  const prev = props.series.map((_, i) => props.previous[i]?.[props.metric] ?? null)

  const accent = cssVar('--accent')
  const compare = cssVar('--compare')
  const ink3 = cssVar('--ink-3')

  // day (YYYY-MM-DD) -> joined annotation text, for markers + tooltip
  const notes = new Map<string, string>()
  for (const a of props.annotations ?? []) {
    notes.set(a.day, notes.has(a.day) ? `${notes.get(a.day)} · ${a.text}` : a.text)
  }
  const days = props.series.map((p) => p.t.slice(0, 10))

  chart = new uPlot(
    {
      width: el.value.clientWidth,
      height: 300,
      cursor: { points: { size: 8 } },
      axes: [
        {
          stroke: ink3,
          grid: { show: false },
          ticks: { show: false },
          font: '12px system-ui',
        },
        {
          stroke: ink3,
          grid: { stroke: 'rgba(128,128,128,0.10)', width: 1 },
          ticks: { show: false },
          font: '12px system-ui',
          size: 44,
        },
      ],
      series: [
        {},
        {
          label: 'previous',
          stroke: compare,
          width: 2,
          dash: [4, 5],
          points: { show: false },
        },
        {
          label: 'current',
          stroke: accent,
          width: 2,
          fill: cssVar('--accent-soft'),
          points: { show: false },
        },
      ],
      hooks: {
        draw: [
          (u) => {
            const { ctx } = u
            ctx.save()
            ctx.strokeStyle = ink3
            ctx.fillStyle = ink3
            ctx.setLineDash([3, 4])
            // annotations are day-granular — skip markers on hourly charts
            if (hourly) {
              ctx.restore()
              return
            }
            days.forEach((day, i) => {
              if (!notes.has(day)) return
              const x = u.valToPos(xs[i], 'x', true)
              ctx.beginPath()
              ctx.moveTo(x, u.bbox.top)
              ctx.lineTo(x, u.bbox.top + u.bbox.height)
              ctx.stroke()
              ctx.beginPath()
              ctx.arc(x, u.bbox.top + 5, 3.5, 0, Math.PI * 2)
              ctx.fill()
            })
            ctx.restore()
          },
        ],
        setCursor: [
          (u) => {
            if (!tip.value) return
            const i = u.cursor.idx
            if (i == null || xs[i] == null) {
              tip.value.style.opacity = '0'
              return
            }
            const d = new Date(xs[i] * 1000)
            const cur = ys[i] ?? 0
            const pre = prev[i]
            tip.value.innerHTML =
              `<span style="color:var(--ink-3)">${hourly ? d.toLocaleTimeString(undefined, { hour: 'numeric' }) : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}</span> ` +
              `<b>${cur.toLocaleString()}</b>` +
              (pre != null ? ` <span style="color:var(--ink-3)">vs ${pre.toLocaleString()}</span>` : '') +
              (notes.has(days[i]) ? `<br><span style="color:var(--ink-2)">📌 ${notes.get(days[i])}</span>` : '')
            tip.value.style.opacity = '1'
            tip.value.style.left = `${u.cursor.left}px`
          },
        ],
      },
      legend: { show: false },
    },
    [xs, prev, ys],
    el.value
  )
}

onMounted(() => {
  build()
  const ro = new ResizeObserver(() => chart?.setSize({ width: el.value!.clientWidth, height: 300 }))
  ro.observe(el.value!)
  onBeforeUnmount(() => {
    ro.disconnect()
    chart?.destroy()
  })
})

watch(() => [props.series, props.metric, props.annotations], build, { deep: true })
</script>

<template>
  <div class="relative">
    <div ref="el" />
    <div
      ref="tip"
      class="pointer-events-none absolute top-0 -translate-x-1/2 rounded-md px-2.5 py-1 text-sm card transition-opacity"
      style="opacity: 0"
    />
  </div>
</template>
