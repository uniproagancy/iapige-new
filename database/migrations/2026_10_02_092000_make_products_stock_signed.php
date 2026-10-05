<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock is allowed to go below zero.
 *
 * It stopped being a gate on selling — suppliers report it too unevenly for
 * that — so the column is now a record of what they told us minus what we
 * sold. That figure is sometimes negative, which an unsigned column refuses
 * to hold, and the refusal surfaced as a failed checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('stock')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('stock')->default(0)->change();
        });
    }
};
