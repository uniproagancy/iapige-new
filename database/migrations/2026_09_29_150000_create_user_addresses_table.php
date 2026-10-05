<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses a customer keeps, so the second order costs them four taps
 * instead of a form. The order still stores its own copy of the address at
 * the time of sale — an address edited later must not rewrite history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('delivery_cities')->nullOnDelete();

            $table->string('label', 40)->nullable();      // "home", "office"
            $table->string('name');
            $table->string('phone', 32);
            $table->string('address');
            $table->string('note')->nullable();           // entrance, floor, landmark
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_addresses');
    }
};
