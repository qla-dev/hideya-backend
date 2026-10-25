<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Accounts come from Sign in with Apple / Google: no passwords, and Apple may hide the email.
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->string('apple_sub')->nullable()->unique();
            $table->string('google_sub')->nullable()->unique();
            $table->timestamp('hosting_unlocked_at')->nullable();
        });

        Schema::create('push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 191)->unique(); // ExponentPushToken[...]
            $table->string('platform', 8)->nullable();
            $table->timestamps();
        });

        Schema::table('players', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('room_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('players', fn (Blueprint $table) => $table->dropConstrainedForeignId('user_id'));
        Schema::dropIfExists('push_tokens');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['apple_sub']);
            $table->dropUnique(['google_sub']);
            $table->dropColumn(['apple_sub', 'google_sub', 'hosting_unlocked_at']);
        });
    }
};
