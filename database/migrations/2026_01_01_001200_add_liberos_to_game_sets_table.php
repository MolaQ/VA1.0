<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_sets', function (Blueprint $table) {
            // Two liberos split by phase: one on the court when we receive, one when we serve.
            // The same player in both (or only one set) = a single libero.
            $table->foreignId('libero_reception_id')->nullable()->after('our_side')->constrained('players')->nullOnDelete();
            $table->foreignId('libero_defense_id')->nullable()->after('libero_reception_id')->constrained('players')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('game_sets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('libero_reception_id');
            $table->dropConstrainedForeignId('libero_defense_id');
        });
    }
};
