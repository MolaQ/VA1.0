<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Players can be known only by their jersey number (e.g. added on the fly while coding
     * or detected by the AI) and get a name or nickname later.
     */
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->string('first_name')->nullable()->change();
            $table->string('last_name')->nullable()->change();
            $table->string('nickname', 40)->nullable()->after('last_name');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('nickname');
        });
    }
};
