<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-site revenue currency (ISO 4217, e.g. 'USD'). Null = plain numbers —
// goal revenue stays currency-neutral until the site picks one.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sites', 'currency')) {
            Schema::table('sites', function (Blueprint $table) {
                $table->string('currency', 3)->nullable()->after('timezone');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sites', 'currency')) {
            Schema::table('sites', function (Blueprint $table) {
                $table->dropColumn('currency');
            });
        }
    }
};
