<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['rollup_hourly', 'rollup_daily'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedInteger('sessions')->default(0);
                $t->unsignedInteger('bounces')->default(0);
                $t->unsignedBigInteger('duration_sum')->default(0); // seconds
            });
        }
    }

    public function down(): void
    {
        foreach (['rollup_hourly', 'rollup_daily'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['sessions', 'bounces', 'duration_sum']);
            });
        }
    }
};
