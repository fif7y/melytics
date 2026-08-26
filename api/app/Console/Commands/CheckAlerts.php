<?php

namespace App\Console\Commands;

use App\Models\Annotation;
use App\Models\Site;
use App\Services\Stats;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class CheckAlerts extends Command
{
    protected $signature = 'melytics:alerts';

    protected $description = 'Detect traffic spikes/drops vs the trailing week and alert by email + chart annotation';

    // Spike: today >= 2x the 7-day median for the same clock window (and >= MIN_VISITORS).
    // Drop: today <= half the median (median itself >= MIN_VISITORS, so quiet sites stay quiet).
    private const SPIKE_FACTOR = 2.0;

    private const DROP_FACTOR = 0.5;

    private const MIN_VISITORS = 10;

    private const MIN_HOUR = 6; // no verdicts before 6am site-local

    public function handle(): int
    {
        foreach (Site::all() as $site) {
            $now = now($site->timezone);
            if ($now->hour < self::MIN_HOUR) {
                continue;
            }

            $today = $this->visitorsSince($site, $now->copy()->startOfDay(), $now);
            $baseline = [];
            for ($d = 1; $d <= 7; $d++) {
                $baseline[] = $this->visitorsSince(
                    $site,
                    $now->copy()->subDays($d)->startOfDay(),
                    $now->copy()->subDays($d)
                );
            }
            sort($baseline);
            $median = $baseline[3];

            // median >= 1 keeps brand-new sites (empty trailing week) from alerting daily
            $spike = $median >= 1 && $today >= self::MIN_VISITORS && $today >= $median * self::SPIKE_FACTOR;
            $drop = $median >= self::MIN_VISITORS && $today <= $median * self::DROP_FACTOR;
            if (! $spike && ! $drop) {
                continue;
            }

            // One alert per site per day — the annotation doubles as the dedupe record
            $day = $now->toDateString();
            if (Annotation::where('site_id', $site->id)->where('day', $day)->where('text', 'like', '⚠%')->exists()) {
                continue;
            }

            $kind = $spike ? 'spike' : 'drop';
            $text = "⚠ Traffic {$kind}: {$today} visitors by {$now->format('H:i')}, ~{$median} typical";
            $site->annotations()->create(['day' => $day, 'text' => $text]);

            Mail::raw(
                "{$site->domain} — {$text}.\n\nCompared to the median of the same window over the last 7 days.\n\nhttps://stats.{$site->domain}/",
                fn ($m) => $m->to($site->user->email)->subject("[melytics] Traffic {$kind} on {$site->domain}")
            );
            $this->info("{$site->domain}: {$kind} alert sent ({$today} vs ~{$median})");
        }

        return self::SUCCESS;
    }

    /** Distinct visitors between two site-local instants (pageviews only). */
    private function visitorsSince(Site $site, $from, $to): int
    {
        return DB::table('hits')
            ->where('site_id', $site->id)
            ->whereNull('event')
            ->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->distinct()
            ->count('visitor_hash');
    }
}
