<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Power-ups are owned by kind (ghost, decoy, shrink, flare), not as one number.
// Existing credits are dealt out across the four kinds.
return new class extends Migration
{
    private const KINDS = ['ghost', 'decoy', 'shrink', 'flare'];

    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->json('power_ups')->nullable());
        foreach (DB::table('users')->where('power_up_credits', '>', 0)->get(['id', 'power_up_credits']) as $user) {
            $owned = array_fill_keys(self::KINDS, 0);
            for ($i = 0; $i < $user->power_up_credits; $i++) {
                $owned[self::KINDS[$i % 4]]++;
            }
            DB::table('users')->where('id', $user->id)->update(['power_ups' => json_encode($owned), 'power_up_credits' => 0]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('users')->whereNotNull('power_ups')->get(['id', 'power_ups']) as $user) {
            DB::table('users')->where('id', $user->id)->update(['power_up_credits' => array_sum(json_decode($user->power_ups, true) ?: [])]);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('power_ups'));
    }
};
