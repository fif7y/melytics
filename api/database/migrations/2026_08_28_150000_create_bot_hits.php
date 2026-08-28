<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Blocked-at-ingest bot pageviews. Never mixed into hits: human stats
        // queries and rollups stay untouched; the dashboard Bots card reads this.
        Schema::create('bot_hits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('name', 64); // crawler label ('Googlebot', 'Asset scraper', …)
            $table->string('path', 512);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['site_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_hits');
    }
};
