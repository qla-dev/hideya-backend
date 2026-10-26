<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per store transaction RevenueCat reported for an account, so
     * each pack is granted once. Purchases are verified with RevenueCat's API
     * now, so the webhook's event log goes.
     */
    public function up(): void
    {
        Schema::create('revenuecat_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('transaction_id')->unique();
            $table->string('product_id');
            $table->unsignedInteger('power_ups')->default(0);
            $table->timestamp('purchased_at')->nullable();
            $table->timestamps();
        });
        Schema::dropIfExists('revenuecat_events');
    }

    public function down(): void
    {
        Schema::create('revenuecat_events', function (Blueprint $table) {
            $table->string('id', 191)->primary();
            $table->timestamp('created_at')->nullable();
        });
        Schema::dropIfExists('revenuecat_purchases');
    }
};
