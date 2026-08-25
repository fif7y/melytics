<?php

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class Prune extends Command
{
    protected $signature = 'melytics:prune';

    protected $description = 'Delete raw hits older than each site\'s retention window (rollups are kept forever)';

    public function handle(): int
    {
        foreach (Site::all() as $site) {
            $deleted = DB::table('hits')
                ->where('site_id', $site->id)
                ->where('created_at', '<', now()->subDays($site->retention_days))
                ->delete();
            if ($deleted) {
                $this->info("{$site->domain}: pruned $deleted hits");
            }
        }

        return self::SUCCESS;
    }
}
