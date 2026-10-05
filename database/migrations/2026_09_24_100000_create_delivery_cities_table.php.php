<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courier zones. Each city carries its own fee, its own free-delivery
 * threshold and how long it takes, so the admin can change prices without
 * touching code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_cities', function (Blueprint $table) {
            $table->id();
            $table->decimal('fee', 8, 2)->default(0);
            $table->decimal('free_from', 10, 2)->nullable();   // null = never free
            $table->unsignedSmallInteger('days')->default(1);  // delivery time, in days
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('delivery_city_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_city_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name');

            $table->unique(['delivery_city_id', 'locale']);
            $table->foreign('locale')->references('code')->on('languages')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });
		
		Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('delivery_city_id')->nullable()->after('city')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_city_translations');
        Schema::dropIfExists('delivery_cities');
    }
};