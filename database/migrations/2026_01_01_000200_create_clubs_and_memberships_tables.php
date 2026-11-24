<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clubs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('city')->nullable();
            $table->string('country', 2)->default('PL');
            $table->string('accent_color', 7)->nullable();
            $table->string('logo_path')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('password');
            $table->string('locale', 5)->nullable()->after('is_super_admin');
            $table->string('theme', 10)->nullable()->after('locale');
            $table->foreignId('current_club_id')->nullable()->after('is_super_admin')
                ->constrained('clubs')->nullOnDelete();
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->timestamps();

            $table->unique(['club_id', 'user_id', 'role']);
            $table->index(['user_id', 'club_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_club_id');
            $table->dropColumn(['is_super_admin', 'locale', 'theme']);
        });

        Schema::dropIfExists('clubs');
    }
};
