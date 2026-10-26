<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->double('metres')->default(0);
            $table->decimal('distance_lat', 10, 7)->nullable();
            $table->decimal('distance_lng', 10, 7)->nullable();
        });

        Schema::create('round_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Keep account history if an old room/player is removed.
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_id', 96);
            $table->string('source', 8); // online | demo
            $table->string('team', 8);
            $table->string('outcome', 16);
            $table->unsignedInteger('seconds');
            $table->unsignedInteger('metres');
            $table->boolean('won');
            $table->timestamp('played_at', 3);
            $table->unique(['user_id', 'source_id']);
            $table->index(['user_id', 'played_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('round_results');
        Schema::table('players', fn (Blueprint $table) => $table->dropColumn(['metres', 'distance_lat', 'distance_lng']));
    }
};
