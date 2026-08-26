<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Stats;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class Rollup extends Command
{
    protected $signature = 'melytics:rollup {--hours=3 : Recompute this many trailing hours (and the days they touch)}';

    protected $description = 'Recompute hourly/daily rollups from raw hits (idempotent, cron-safe)';

    private const DIMENSIONS = [
        'total' => null,
        'page' => 'path',
        'referrer' => 'referrer_host',
        'country' => 'country',
        'device' => 'device',
        'browser' => 'browser',
        'os' => 'os',
        'utm_source' => 'utm_source',
        'utm_medium' => 'utm_medium',
        'utm_campaign' => 'utm_campaign',
        'event' => 'event',
    ];

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $from = now()->subHours($hours)->startOfHour();

        foreach (Site::pluck('id') as $siteId) {
            $this->rollupPeriod($siteId, 'rollup_hourly', 'ts', "strftime('%Y-%m-%d %H:00:00', created_at)", $from);
            $this->rollupPeriod($siteId, 'rollup_daily', 'day', "date(created_at)", $from->copy()->startOfDay());
        }

        $this->info('Rollups updated from '.$from->toDateTimeString());

        return self::SUCCESS;
    }

    private function rollupPeriod(int $siteId, string $table, string $periodCol, string $sqlitePeriodExpr, $from): void
    {
        $driver = DB::connection()->getDriverName();
        $periodExpr = $driver === 'mysql'
            ? ($periodCol === 'ts' ? "DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')" : 'DATE(created_at)')
            : $sqlitePeriodExpr;

        // The period column stores a date string for daily rows; comparing it
        // against a full datetime silently skips them (string comparison).
        $periodFrom = $periodCol === 'day' ? $from->toDateString() : $from->toDateTimeString();

        DB::transaction(function () use ($siteId, $table, $periodCol, $periodExpr, $from, $periodFrom) {
            DB::table($table)
                ->where('site_id', $siteId)
                ->where($periodCol, '>=', $periodFrom)
                ->delete();

            foreach (self::DIMENSIONS as $dimension => $column) {
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
            $jsonUrl = DB::connection()->getDriverName() === 'mysql'
                ? "JSON_UNQUOTE(JSON_EXTRACT(event_props, '$.url'))"
                : "json_extract(event_props, '$.url')";
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
            DB::table($table)->insert(array_map(fn ($r) => [
                'site_id' => $siteId, $periodCol => $r->p, 'dimension' => $dimension,
                'value' => $r->v ?? '', 'pageviews' => $r->s, 'visitors' => $r->u,
                'sessions' => $r->s,
            ], $rows));
        }
    }
}
