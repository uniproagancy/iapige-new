<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_city_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('up_to_weight');   // grams — this tier applies up to here
            $table->decimal('fee', 8, 2);
            $table->timestamps();

            $table->unique(['delivery_city_id', 'up_to_weight']);
        });

        Schema::table('delivery_cities', function (Blueprint $table) {
            $table->decimal('bulky_fee', 8, 2)->nullable()->after('fee');
            $table->decimal('per_kg_over', 8, 2)->nullable()->after('bulky_fee');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_cities', function (Blueprint $table) {
            $table->dropColumn(['bulky_fee', 'per_kg_over']);
        });

        Schema::dropIfExists('delivery_rates');
    }
};