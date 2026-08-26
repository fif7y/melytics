<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hits', function (Blueprint $table) {
            // Tier-2 (consented) persistent visitor id from localStorage; null for tier-1 hits
            $table->string('visitor_id', 32)->nullable()->after('visitor_hash');
            $table->index(['site_id', 'visitor_id']);
        });
    }

    public function down(): void
    {
        Schema::table('hits', function (Blueprint $table) {
            $table->dropIndex(['site_id', 'visitor_id']);
            $table->dropColumn('visitor_id');
        });
    }
};
