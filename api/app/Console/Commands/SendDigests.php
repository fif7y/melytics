<?php

namespace App\Console\Commands;

use App\Mail\WeeklyDigest;
use App\Models\Site;
use App\Services\Stats;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendDigests extends Command
{
    protected $signature = 'melytics:digest {--site= : Send only for this site id (any site, even with digest off)}';

    protected $description = 'Send the weekly email digest for each digest-enabled site';

    public function handle(Stats $stats): int
    {
        $sites = $this->option('site')
            ? Site::where('id', $this->option('site'))->get()
            : Site::where('digest_enabled', true)->get();

        foreach ($sites as $site) {
            [$from, $to] = [now()->subDays(7)->startOfDay(), now()->subDay()->startOfDay()];
            $overview = $stats->overview($site, $from, $to, 'day');
            if ($overview['totals']['pageviews'] === 0) {
                $this->info("{$site->domain}: no traffic, skipped");
                continue;
            }

            Mail::to($site->user->email)->send(new WeeklyDigest(
                $site,
                $overview,
                $stats->breakdown($site, 'page', $from, $to, 5)->all(),
                $stats->breakdown($site, 'referrer', $from, $to, 5)->all(),
                $stats->goals($site, $from, $to),
            ));
            $this->info("{$site->domain}: digest sent to {$site->user->email}");
        }

        return self::SUCCESS;
    }
}
