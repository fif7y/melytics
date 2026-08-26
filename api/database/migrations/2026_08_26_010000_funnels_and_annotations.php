<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funnels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Ordered steps: [{name, event|path_pattern}, ...]
            $table->json('steps');
            $table->timestamps();
        });

        Schema::create('annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('text');
            $table->timestamps();
            $table->index(['site_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funnels');
        Schema::dropIfExists('annotations');
    }
};
