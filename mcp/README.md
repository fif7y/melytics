# melytics × AI assistants (MCP)

Every melytics install is an MCP server — your analytics, queryable by Claude
or any MCP-capable assistant. No terminal, nothing extra to install.

## Connect Claude (easiest)

1. In your dashboard: **Account → AI assistants → Set up → Generate connector URL**, copy the URL.
2. In Claude (claude.ai or the apps): **Settings → Connectors → Add custom connector**, paste the URL.
3. Ask away: *"How was traffic on my blog this week, and where did visitors come from?"*

The URL contains a secret — treat it like a password. Generating a new one
revokes the previous URL.

## Other MCP clients

Any client that speaks Streamable HTTP works. Two options:

- **URL with embedded token** (for clients without header support): the connector URL from the dashboard.
- **Bearer header**: endpoint `https://stats.example.com/api/mcp` with `Authorization: Bearer <token>`.

Claude Code:

```bash
claude mcp add melytics --transport http https://stats.example.com/api/mcp --header "Authorization: Bearer <token>"
```

## Tools

| Tool | What it answers |
|---|---|
| `list_sites` | Which sites does this instance track? |
| `get_stats` | Traffic over time, totals, previous-period comparison |
| `get_breakdown` | Top pages, referrers, countries, devices, browsers, OS, UTM, events |
| `get_goals` | Goal conversions and rates |
| `get_funnels` | Funnel step counts and drop-off |
| `get_vitals` | p75 Core Web Vitals (LCP, CLS, INP, TTFB) |
| `get_live` | Who's on the site right now |
| `get_annotations` | Deploys/launches/campaigns noted on the chart |

All read-only; date args are `YYYY-MM-DD`, defaulting to the last 30 days.

## Local stdio server (optional)

`index.js` in this folder is the same 8 tools as a local stdio MCP server, for
setups that prefer not to expose the HTTP endpoint. Needs Node 18+:

```bash
npm install --prefix mcp
claude mcp add melytics -e MELYTICS_URL=https://stats.example.com/api -e MELYTICS_TOKEN=<token> -- node /path/to/melytics/mcp/index.js
```
