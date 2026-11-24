<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cookie-less, first-party analytics. No IP address or user agent is stored:
 * visitors are identified by a hash of (daily salt + IP + user agent) that
 * cannot be linked across days once the salt rotates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->char('visitor_hash', 64);
            $table->string('path', 255);
            $table->string('route', 100)->nullable();
            $table->string('referrer_host', 120)->nullable();
            $table->string('utm_source', 80)->nullable();
            $table->string('device', 10);
            $table->string('browser', 30)->nullable();
            $table->string('os', 30)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('locale', 5)->nullable();
            $table->boolean('is_pwa')->default(false);
            $table->boolean('is_entry')->default(false);
            $table->unsignedInteger('engaged_seconds')->default(0);
            $table->timestamp('viewed_at')->index();

            $table->index(['club_id', 'viewed_at']);
            $table->index(['visitor_hash', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_views');
    }
};
