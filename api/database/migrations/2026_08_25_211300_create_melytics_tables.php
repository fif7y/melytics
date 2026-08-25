<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('domain');
            $table->string('key', 24)->unique();
            $table->string('timezone', 64)->default('UTC');
            $table->unsignedSmallInteger('retention_days')->default(90);
            $table->boolean('tier2_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('hits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->char('visitor_hash', 16);
            $table->string('path', 512);
            $table->string('referrer_host')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->char('country', 2)->nullable();
            $table->string('device', 16)->nullable();
            $table->string('browser', 32)->nullable();
            $table->string('os', 32)->nullable();
            $table->unsignedSmallInteger('screen_w')->nullable();
            $table->string('event', 64)->nullable(); // null = pageview
            $table->json('event_props')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['site_id', 'created_at']);
        });

        // One rollup row per (site, period, dimension, value).
        // dimension: total|page|referrer|country|device|browser|utm_source|utm_medium|utm_campaign|event
        foreach (['rollup_hourly' => 'ts', 'rollup_daily' => 'day'] as $name => $col) {
            Schema::create($name, function (Blueprint $table) use ($col) {
                $table->id();
                $table->foreignId('site_id')->constrained()->cascadeOnDelete();
                if ($col === 'ts') {
                    $table->timestamp('ts');
                } else {
                    $table->date('day');
                }
                $table->string('dimension', 16);
                $table->string('value', 512)->default('');
                $table->unsignedInteger('pageviews')->default(0);
                $table->unsignedInteger('visitors')->default(0);
                $table->unique(['site_id', $col, 'dimension', 'value'], $col.'_rollup_unique');
            });
        }

        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('event', 64)->nullable();
            $table->string('path_pattern', 512)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
        Schema::dropIfExists('rollup_daily');
        Schema::dropIfExists('rollup_hourly');
        Schema::dropIfExists('hits');
        Schema::dropIfExists('sites');
    }
};
