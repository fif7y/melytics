<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('token', 32)->unique();
            $table->string('password_hash')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('digest_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_links');
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('digest_enabled');
        });
    }
};
