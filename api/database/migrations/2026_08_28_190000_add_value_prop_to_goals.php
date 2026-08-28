<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Revenue goals: the event_props key whose numeric value is summed for a goal
// (e.g. 'value' on a 'purchase' event). Null = a plain conversion goal.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('goals', 'value_prop')) {
            Schema::table('goals', function (Blueprint $table) {
                $table->string('value_prop', 64)->nullable()->after('path_pattern');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('goals', 'value_prop')) {
            Schema::table('goals', function (Blueprint $table) {
                $table->dropColumn('value_prop');
            });
        }
    }
};
