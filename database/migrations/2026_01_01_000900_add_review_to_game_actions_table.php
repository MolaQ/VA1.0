<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_actions', function (Blueprint $table) {
            // "To clarify": the AI (or a coder) wasn't sure; the analyst confirms or corrects it.
            $table->boolean('needs_review')->default(false)->after('confidence');
            $table->string('review_note', 255)->nullable()->after('needs_review');
            // Set once a person confirmed/corrected it: the analyzer no longer overwrites it.
            $table->timestamp('reviewed_at')->nullable()->after('review_note');

            $table->index(['game_id', 'needs_review']);
        });
    }

    public function down(): void
    {
        Schema::table('game_actions', function (Blueprint $table) {
            $table->dropIndex(['game_id', 'needs_review']);
            $table->dropColumn(['needs_review', 'review_note', 'reviewed_at']);
        });
    }
};
