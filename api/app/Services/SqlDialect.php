<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** SQL expressions that differ between SQLite (shared hosting) and MySQL. */
class SqlDialect
{
    private static function mysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    /** Unix-epoch expression for a datetime column. */
    public static function epoch(string $column): string
    {
        return self::mysql()
            ? "UNIX_TIMESTAMP($column)"
            : "CAST(strftime('%s', $column) AS INTEGER)";
    }

    /** JSON url extractor for __outbound / __download event props. */
    public static function jsonUrl(): string
    {
        return self::mysql()
            ? "JSON_UNQUOTE(JSON_EXTRACT(event_props, '$.url'))"
            : "json_extract(event_props, '$.url')";
    }

    /** Numeric JSON prop from event_props as a float, NULL when absent or non-numeric. */
    public static function jsonNum(string $field): string
    {
        return self::mysql()
            ? "CASE WHEN JSON_TYPE(JSON_EXTRACT(event_props, '$.$field')) IN ('INTEGER','DOUBLE','DECIMAL','UNSIGNED INTEGER') THEN CAST(JSON_EXTRACT(event_props, '$.$field') AS DOUBLE) END"
            : "CASE WHEN typeof(json_extract(event_props, '$.$field')) IN ('integer','real') THEN CAST(json_extract(event_props, '$.$field') AS REAL) END";
    }

    /** Period expression bucketing a UTC datetime column into site-local time. */
    public static function periodExpr(string $column, string $grain, int $offsetMin): string
    {
        $shifted = $offsetMin === 0 ? $column
            : (self::mysql()
                ? "DATE_ADD($column, INTERVAL $offsetMin MINUTE)"
                : "datetime($column, '$offsetMin minutes')");

        return $grain === 'hour'
            ? (self::mysql() ? "DATE_FORMAT($shifted, '%Y-%m-%d %H:00:00')" : "strftime('%Y-%m-%d %H:00:00', $shifted)")
            : (self::mysql() ? "DATE($shifted)" : "date($shifted)");
    }

    /** Week index of a UTC datetime column since Monday 2024-01-01, in site-local time. */
    public static function weekIndex(string $column, int $offsetMin): string
    {
        return self::mysql()
            ? "FLOOR(DATEDIFF(DATE_ADD($column, INTERVAL $offsetMin MINUTE), '2024-01-01') / 7)"
            : "CAST((julianday(date(datetime($column, '$offsetMin minutes'))) - julianday('2024-01-01')) / 7 AS INTEGER)";
    }
}
