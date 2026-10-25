<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 6)->unique();
            $table->string('status', 16)->default('lobby'); // lobby | playing | ended
            $table->json('rules');
            $table->decimal('center_lat', 10, 7)->nullable();
            $table->decimal('center_lng', 10, 7)->nullable();
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('ended_at', 3)->nullable();
            $table->string('outcome', 16)->nullable(); // huntersWin | runnersWin | cancelled
            $table->json('fired')->nullable(); // round events already announced
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });

        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('name', 24);
            $table->char('token_hash', 64)->unique();
            $table->boolean('is_host')->default(false);
            $table->string('pick', 8)->default('random'); // hunter | runner | random
            $table->string('team', 8)->nullable(); // set when the round starts
            $table->boolean('ready')->default(false);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamp('located_at', 3)->nullable();
            $table->timestamp('tagged_at', 3)->nullable();
            $table->timestamp('left_at', 3)->nullable();
            $table->timestamps();
            $table->index(['room_id', 'team']);
        });

        Schema::create('radio_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->string('team', 8)->nullable(); // null: everyone
            $table->string('kind', 8); // chat | event
            $table->string('text', 300)->nullable();
            $table->string('key', 64)->nullable(); // event copy, localised by the client
            $table->json('values')->nullable();
            $table->timestamp('created_at', 3)->nullable();
            $table->index(['room_id', 'id']);
        });

        Schema::create('room_pings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('second'); // seconds into the round
            $table->json('positions'); // untagged runners at the ping: [{id, lat, lng}]
            $table->timestamp('created_at', 3)->nullable();
            $table->unique(['room_id', 'second']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_pings');
        Schema::dropIfExists('radio_messages');
        Schema::dropIfExists('players');
        Schema::dropIfExists('rooms');
    }
};
