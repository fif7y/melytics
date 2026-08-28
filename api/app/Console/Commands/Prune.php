<?php

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class Prune extends Command
{
    protected $signature = 'melytics:prune';

    protected $description = 'Delete raw hits older than each site\'s retention window (daily rollups are kept forever)';

    /** Hourly rollups only serve ranges ≤2 days; beyond this they are never read. */
    private const HOURLY_KEEP_DAYS = 14;

    public function handle(): int
    {
        $botTable = \Illuminate\Support\Facades\Schema::hasTable('bot_hits');
        foreach (Site::all() as $site) {
            $deleted = DB::table('hits')
                ->where('site_id', $site->id)
                ->where('created_at', '<', now()->subDays($site->retention_days))
                ->delete();
            if ($deleted) {
                $this->info("{$site->domain}: pruned $deleted hits");
            }
            if ($botTable) {
                DB::table('bot_hits')
                    ->where('site_id', $site->id)
                    ->where('created_at', '<', now()->subDays($site->retention_days))
                    ->delete();
            }
        }

        // ts holds site-local labels; the 14-day margin dwarfs any tz offset
        $hourly = DB::table('rollup_hourly')
            ->where('ts', '<', now()->subDays(self::HOURLY_KEEP_DAYS)->toDateTimeString())
            ->delete();
        if ($hourly) {
            $this->info("pruned $hourly hourly rollup rows");
        }

        return self::SUCCESS;
    }
}
