<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A player blocking another from the chat. With accounts on both sides the block
        // follows them into later games; admins can read these in the database.
        Schema::create('chat_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('blocker_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->foreignId('blocker_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->foreignId('blocked_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('blocked_name', 40);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['blocker_player_id', 'blocked_player_id']);
            $table->index(['blocker_user_id', 'blocked_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_blocks');
    }
};
