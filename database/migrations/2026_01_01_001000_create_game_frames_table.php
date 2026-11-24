<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Players on the court at one moment of the video, seen from above (2D court view).
        // One row per moment, the players as JSON: a match at 2 frames/s is ~15k rows, not 200k.
        // Court coordinates in metres, as the camera sees the court: x 0..9 across,
        // y 0..18 along (0 = near end line, 9 = net, 18 = far end line).
        Schema::create('game_frames', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->decimal('t', 9, 2); // video time, seconds
            $table->string('source', 10)->default('ai'); // ai | manual
            // [{team: us|them, n: jersey|null, k: track id|null, x, y, f: facing degrees|null}]
            $table->json('players');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['game_id', 't']);
            $table->unique(['game_id', 'source', 't']);
        });

        // Jersey numbers the analyst gave to analyzer tracks: one click labels the whole track.
        Schema::create('game_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('track');
            $table->string('team', 4)->nullable();
            $table->unsignedTinyInteger('number')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'track']);
        });

        Schema::table('game_sets', function (Blueprint $table) {
            // Which half of the camera picture we defend in this set: near | far.
            $table->string('our_side', 4)->nullable()->after('we_serve_first');
        });

        Schema::table('game_actions', function (Blueprint $table) {
            // Ball points in metres, in OUR team's frame: x 0..9 left to right as we face the net,
            // y 0 = our end line, 9 = net, 18 = opponent end line.
            // Serve: to = where it landed / was received. Reception: from = contact, to = where the pass went.
            $table->decimal('from_x', 4, 2)->nullable()->after('zone_to');
            $table->decimal('from_y', 4, 2)->nullable()->after('from_x');
            $table->decimal('to_x', 4, 2)->nullable()->after('from_y');
            $table->decimal('to_y', 4, 2)->nullable()->after('to_x');
        });
    }

    public function down(): void
    {
        Schema::table('game_actions', fn (Blueprint $table) => $table->dropColumn(['from_x', 'from_y', 'to_x', 'to_y']));
        Schema::table('game_sets', fn (Blueprint $table) => $table->dropColumn('our_side'));
        Schema::dropIfExists('game_tracks');
        Schema::dropIfExists('game_frames');
    }
};
