<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_sets', function (Blueprint $table) {
            // Who served the first rally of the set; null = unknown (the AI guesses).
            $table->boolean('we_serve_first')->nullable()->after('video_start');
        });
    }

    public function down(): void
    {
        Schema::table('game_sets', function (Blueprint $table) {
            $table->dropColumn('we_serve_first');
        });
    }
};
