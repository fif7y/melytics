<?php

namespace App\Console\Commands;

use App\Models\Site;
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

        DB::transaction(function () use ($siteId, $table, $periodCol, $periodExpr, $from) {
            DB::table($table)
                ->where('site_id', $siteId)
                ->where($periodCol, '>=', $from)
                ->delete();

            foreach (self::DIMENSIONS as $dimension => $column) {
                $valueExpr = $column === null ? "''" : "COALESCE($column, '')";
                $filter = $dimension === 'event' ? 'AND event IS NOT NULL' : '';
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
        });
    }
}
