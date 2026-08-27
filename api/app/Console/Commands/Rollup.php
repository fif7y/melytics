<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\SqlDialect;
use App\Services\Stats;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class Rollup extends Command
{
    protected $signature = 'melytics:rollup
        {--hours=3 : Recompute this many trailing hours (and the days they touch)}
        {--lazy : Internal — this run was triggered by a stats request, not cron}';

    protected $description = 'Recompute hourly/daily rollups from raw hits (idempotent, cron-safe)';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $from = now()->subHours($hours)->startOfHour();

        // Rollup keys are site-local time (per-site offset, sampled now — historic
        // DST edges may mis-bucket an hour, acceptable), so days flip at the
        // site's midnight rather than UTC's.
        foreach (Site::all(['id', 'timezone']) as $site) {
            $offset = Stats::tzOffset($site->timezone ?? 'UTC');
            $this->rollupPeriod($site->id, 'rollup_hourly', 'ts', 'hour', $offset, $from);
            // daily scan starts at the SITE-LOCAL day start containing $from, as a UTC instant
            $dayFrom = $from->copy()->addMinutes($offset)->startOfDay()->subMinutes($offset);
            $this->rollupPeriod($site->id, 'rollup_daily', 'day', 'day', $offset, $dayFrom);
        }

        \App\Support\RollupHeartbeat::beat($this->option('lazy') ? 'lazy' : 'cron');
        $this->info('Rollups updated from '.$from->toDateTimeString());

        return self::SUCCESS;
    }

    private function rollupPeriod(int $siteId, string $table, string $periodCol, string $grain, int $offset, $from): void
    {
        $periodExpr = SqlDialect::periodExpr('created_at', $grain, $offset);

        // The period column stores site-local labels; the delete boundary must be
        // the site-local label of $from. Daily rows store a date string — comparing
        // it against a full datetime silently skips them (string comparison).
        $localFrom = $from->copy()->addMinutes($offset);
        $periodFrom = $periodCol === 'day' ? $localFrom->toDateString() : $localFrom->toDateTimeString();

        DB::transaction(function () use ($siteId, $table, $periodCol, $periodExpr, $from, $periodFrom) {
            DB::table($table)
                ->where('site_id', $siteId)
                ->where($periodCol, '>=', $periodFrom)
                ->delete();

            // 'total' plus every filterable dimension — one registry, shared with Stats
            foreach (['total' => null] + Stats::FILTERABLE as $dimension => $column) {
                $valueExpr = $column === null ? "''" : "COALESCE($column, '')";
                // internal events (__vitals, …) stay out of the event breakdown
                $filter = $dimension === 'event' ? "AND event IS NOT NULL AND substr(event, 1, 2) != '__'" : '';
                // pageviews exclude custom events for every dimension except "event"
                $pvFilter = $dimension === 'event' ? '' : 'AND event IS NULL';

                DB::insert(
                    "INSERT INTO $table (site_id, $periodCol, dimension, value, pageviews, visitors)
                     SELECT site_id, $periodExpr, ?, $valueExpr, COUNT(*), COUNT(DISTINCT visitor_hash)
                     FROM hits
                     WHERE site_id = ? AND created_at >= ? $filter $pvFilter
                     GROUP BY site_id, $periodExpr, $valueExpr",
                    [$dimension, $siteId, $from]
                );
            }

            // outbound / download / not_found — value comes from event props (url) or path
            $jsonUrl = SqlDialect::jsonUrl();
            foreach (Stats::EVENT_DIMENSIONS as $dimension => $event) {
                $valueExpr = $dimension === 'not_found' ? "COALESCE(path, '')" : "COALESCE($jsonUrl, '')";
                DB::insert(
                    "INSERT INTO $table (site_id, $periodCol, dimension, value, pageviews, visitors)
                     SELECT site_id, $periodExpr, ?, $valueExpr, COUNT(*), COUNT(DISTINCT visitor_hash)
                     FROM hits
                     WHERE site_id = ? AND created_at >= ? AND event = ?
                     GROUP BY site_id, $periodExpr, $valueExpr",
                    [$dimension, $siteId, $from, $event]
                );
            }

            $this->rollupSessions($siteId, $table, $periodCol, $periodExpr, $from);
        });
    }

    /**
     * Session metrics per period: sessions/bounces/duration onto the existing
     * 'total' rows, plus entry_page / exit_page dimension rows. Sessions bucket
     * by their first pageview, so a matching 'total' row always exists. The
     * lookback lets sessions that started before the recompute window resolve
     * their gap-split correctly without touching rows outside it.
     */
    private function rollupSessions(int $siteId, string $table, string $periodCol, string $periodExpr, $from): void
    {
        $pExpr = str_replace('created_at', 'first_pv', $periodExpr);
        $bind = [
            'site' => $siteId,
            'lookback' => $from->copy()->subHours(6)->toDateTimeString(),
            'to' => now()->toDateTimeString(),
            'from' => $from->toDateTimeString(),
        ];

        $agg = DB::select(Stats::sessionSql()."
            SELECT $pExpr AS p, COUNT(*) AS s,
                   SUM(CASE WHEN pageviews = 1 THEN 1 ELSE 0 END) AS b,
                   COALESCE(SUM(duration), 0) AS d
            FROM sp WHERE first_pv >= :from GROUP BY $pExpr", $bind);
        foreach ($agg as $row) {
            DB::table($table)
                ->where(['site_id' => $siteId, 'dimension' => 'total', $periodCol => $row->p])
                ->update(['sessions' => $row->s, 'bounces' => $row->b, 'duration_sum' => $row->d]);
        }

        // pageviews column doubles as the session count so the existing
        // breakdown query orders and renders these rows unchanged
        foreach (['entry_page' => 'entry_path', 'exit_page' => 'exit_path'] as $dimension => $col) {
            $rows = DB::select(Stats::sessionSql()."
                SELECT $pExpr AS p, $col AS v, COUNT(*) AS s, COUNT(DISTINCT visitor_hash) AS u
                FROM sp WHERE first_pv >= :from GROUP BY $pExpr, $col", $bind);
            // chunked: one giant insert trips SQLite's variable limit on long backfills
            foreach (array_chunk(array_map(fn ($r) => [
                'site_id' => $siteId, $periodCol => $r->p, 'dimension' => $dimension,
                'value' => $r->v ?? '', 'pageviews' => $r->s, 'visitors' => $r->u,
                'sessions' => $r->s,
            ], $rows), 100) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
    }
}
