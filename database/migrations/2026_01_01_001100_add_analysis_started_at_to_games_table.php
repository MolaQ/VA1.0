<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // when an agent took the match: elapsed time and the estimate of the time left
            $table->timestamp('analysis_started_at')->nullable()->after('analysis_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('analysis_started_at');
        });
    }
};
