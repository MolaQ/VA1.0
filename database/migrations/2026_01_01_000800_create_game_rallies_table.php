<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Explicit rally outcomes. Most outcomes are inferred from the coded actions
        // (ace, kill, error...); this table holds the ones that can't be: opponent errors,
        // opponent winners, and what the AI read from the scoreboard.
        Schema::create('game_rallies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_set_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('server', 4)->nullable(); // us | them
            $table->string('winner', 4)->nullable(); // us | them
            $table->decimal('video_time', 9, 2)->nullable();
            $table->string('source', 10)->default('manual');
            $table->timestamps();

            $table->unique(['game_set_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_rallies');
    }
};
