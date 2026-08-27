<?php

namespace App\Support;

// Tracks when rollups last ran, so the dashboard can warn when the cron job
// is missing and stats requests can self-heal without it.
class RollupHeartbeat
{
    // Cron-driven and lazy (request-driven) runs beat separate files: data
    // freshness considers both, but the "cron isn't set up" banner must only
    // clear when cron itself runs — lazy self-healing shouldn't mask it.
    public static function beat(string $kind = 'cron'): void
    {
        @file_put_contents(storage_path("framework/rollup-{$kind}.heartbeat"), (string) time());
    }

    public static function age(string $kind): ?int
    {
        $at = @file_get_contents(storage_path("framework/rollup-{$kind}.heartbeat"));

        return $at === false ? null : max(0, time() - (int) $at);
    }

    public static function cronStale(int $seconds): bool
    {
        $age = self::age('cron');

        return $age === null || $age > $seconds;
    }

    public static function dataStale(int $seconds): bool
    {
        $age = min(self::age('cron') ?? PHP_INT_MAX, self::age('lazy') ?? PHP_INT_MAX);

        return $age > $seconds;
    }
}
