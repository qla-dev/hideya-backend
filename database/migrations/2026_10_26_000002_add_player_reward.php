<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The power-up a signed-in winner got for winning the round (shown on results).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', fn (Blueprint $table) => $table->string('reward', 16)->nullable());
    }

    public function down(): void
    {
        Schema::table('players', fn (Blueprint $table) => $table->dropColumn('reward'));
    }
};
