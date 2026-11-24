<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('opponent');
            $table->dateTime('played_at');
            $table->string('venue')->nullable();
            $table->boolean('is_home')->default(true);
            $table->string('youtube_url')->nullable();
            $table->string('youtube_id', 20)->nullable();
            $table->unsignedTinyInteger('sets_won')->default(0);
            $table->unsignedTinyInteger('sets_lost')->default(0);
            $table->string('analysis_status', 20)->default('pending');
            $table->unsignedTinyInteger('analysis_progress')->default(0);
            $table->text('analysis_message')->nullable();
            $table->timestamp('analysis_updated_at')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'played_at']);
        });

        Schema::create('game_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('number');
            $table->unsignedTinyInteger('score_us')->default(0);
            $table->unsignedTinyInteger('score_them')->default(0);
            $table->decimal('video_start', 9, 2)->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'number']);
        });

        Schema::create('set_lineups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_set_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rotation_position')->nullable();
            $table->boolean('is_substitute')->default(false);
            $table->timestamps();

            $table->unique(['game_set_id', 'player_id']);
        });

        Schema::create('game_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_set_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('rally')->nullable();
            $table->string('skill', 20);
            $table->string('grade', 1);
            $table->decimal('video_time', 9, 2)->nullable();
            $table->unsignedTinyInteger('zone_from')->nullable();
            $table->unsignedTinyInteger('zone_to')->nullable();
            $table->string('source', 10)->default('manual');
            $table->float('confidence')->nullable();
            $table->string('external_id', 100)->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['game_id', 'external_id']);
            $table->index(['game_id', 'player_id', 'skill']);
            $table->index(['game_id', 'video_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_actions');
        Schema::dropIfExists('set_lineups');
        Schema::dropIfExists('game_sets');
        Schema::dropIfExists('games');
    }
};
