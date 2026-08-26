#!/usr/bin/env node
// melytics MCP server — lets AI assistants query your analytics.
// Config via env: MELYTICS_URL (e.g. https://stats.fif7y.com/api), MELYTICS_TOKEN (dashboard API token).
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js'
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js'
import { z } from 'zod'

const BASE = (process.env.MELYTICS_URL ?? 'https://stats.fif7y.com/api').replace(/\/$/, '')
const TOKEN = process.env.MELYTICS_TOKEN
if (!TOKEN) {
  console.error('MELYTICS_TOKEN env var is required (get one by logging into the dashboard API: POST /auth/login)')
  process.exit(1)
}

async function get(path) {
  const res = await fetch(BASE + path, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${TOKEN}` },
  })
  if (!res.ok) throw new Error(`melytics API ${res.status} for ${path}`)
  return res.json()
}

const json = (data) => ({ content: [{ type: 'text', text: JSON.stringify(data, null, 2) }] })

const range = {
  site_id: z.number().describe('Site id (see list_sites)'),
  from: z.string().optional().describe('Start date YYYY-MM-DD (default: 30 days ago)'),
  to: z.string().optional().describe('End date YYYY-MM-DD (default: today)'),
}
const qs = ({ from, to }) =>
  [from && `from=${from}`, to && `to=${to}`].filter(Boolean).join('&')

const server = new McpServer({ name: 'melytics', version: '1.0.0' })

server.tool('list_sites', 'List the sites tracked by this melytics instance', {}, async () =>
  json(await get('/sites'))
)

server.tool(
  'get_stats',
  'Traffic overview for a site: time series, totals, and previous-period comparison',
  range,
  async (a) => json(await get(`/sites/${a.site_id}/stats?${qs(a)}`))
)

server.tool(
  'get_breakdown',
  'Top values for one dimension (pages, referrers, countries, devices, browsers, OS, UTM, events)',
  {
    ...range,
    dimension: z
      .enum(['page', 'referrer', 'country', 'device', 'browser', 'os', 'utm_source', 'utm_medium', 'utm_campaign', 'event'])
      .describe('Dimension to break down by'),
    limit: z.number().optional().describe('Max rows (default 20, max 100)'),
  },
  async (a) =>
    json(await get(`/sites/${a.site_id}/breakdown?dimension=${a.dimension}&${qs(a)}${a.limit ? `&limit=${a.limit}` : ''}`))
)

server.tool('get_goals', 'Goal conversion counts and rates for a site', range, async (a) =>
  json(await get(`/sites/${a.site_id}/goals?${qs(a)}`))
)

server.tool('get_funnels', 'Funnel step-by-step visitor counts and drop-off rates', range, async (a) =>
  json(await get(`/sites/${a.site_id}/funnels?${qs(a)}`))
)

server.tool('get_vitals', 'p75 Core Web Vitals (LCP, CLS, INP, TTFB) for a site', range, async (a) =>
  json(await get(`/sites/${a.site_id}/vitals?${qs(a)}`))
)

server.tool(
  'get_live',
  'Visitors online right now (last 5 minutes) and the pages they are on',
  { site_id: z.number().describe('Site id (see list_sites)') },
  async (a) => json(await get(`/sites/${a.site_id}/live`))
)

server.tool(
  'get_annotations',
  'Chart annotations (deploys, launches, campaigns) noted on the dashboard',
  range,
  async (a) => json(await get(`/sites/${a.site_id}/annotations?${qs(a)}`))
)

await server.connect(new StdioServerTransport())
