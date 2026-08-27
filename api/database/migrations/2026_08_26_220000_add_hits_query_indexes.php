<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Query-path indexes: goals/funnels/vitals/live filter by event, tier-2
// analytics (retention/cohorts/loyalty/attribution) filter by visitor_id.
// Both previously fell back to scanning the (site_id, created_at) range.
return new class extends Migration
{
    public function up(): void
    {
        // hasIndex guard: on prod this DDL is sometimes pre-applied via tinker
        Schema::table('hits', function (Blueprint $table) {
            if (! Schema::hasIndex('hits', ['site_id', 'event', 'created_at'])) {
                $table->index(['site_id', 'event', 'created_at']);
            }
            if (! Schema::hasIndex('hits', ['site_id', 'visitor_id'])) {
                $table->index(['site_id', 'visitor_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('hits', function (Blueprint $table) {
            $table->dropIndex(['site_id', 'event', 'created_at']);
            $table->dropIndex(['site_id', 'visitor_id']);
        });
    }
};
