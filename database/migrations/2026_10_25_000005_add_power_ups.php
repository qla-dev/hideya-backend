<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Power-ups: an account's paid balance and daily free one, charges picked up on
// the map during a round, the room's live pickup and shrink shift, and a log of
// every use (which is also how "once per round" is checked).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('power_up_credits')->default(0);
            $table->date('daily_power_up_on')->nullable();
        });
        Schema::table('players', function (Blueprint $table) {
            $table->unsignedSmallInteger('power_up_charges')->default(0);
        });
        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedInteger('zone_advance')->default(0);
            $table->json('pickup')->nullable();
        });
        Schema::create('power_up_uses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('source', 8);
            $table->unsignedInteger('second');
            $table->unsignedInteger('ends_second')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['room_id', 'kind']);
        });
        Schema::create('revenuecat_events', function (Blueprint $table) {
            $table->string('id', 191)->primary();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenuecat_events');
        Schema::dropIfExists('power_up_uses');
        Schema::table('rooms', fn (Blueprint $table) => $table->dropColumn(['zone_advance', 'pickup']));
        Schema::table('players', fn (Blueprint $table) => $table->dropColumn('power_up_charges'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['power_up_credits', 'daily_power_up_on']));
    }
};
