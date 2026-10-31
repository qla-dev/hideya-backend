<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Player reports from the chat, for admins to read in the database. Names and the
        // message text are copied so a report survives the room being cleaned up.
        Schema::create('chat_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('room_code', 8);
            $table->foreignId('chat_message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reporter_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->foreignId('reporter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reporter_name', 40);
            $table->foreignId('reported_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->foreignId('reported_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reported_name', 40);
            $table->text('message_text');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['chat_message_id', 'reporter_player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_reports');
    }
};
