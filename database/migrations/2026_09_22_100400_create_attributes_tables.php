<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('type', 16)->default('select');
            $table->string('unit', 16)->nullable();
            $table->boolean('is_filterable')->default(false);
            $table->boolean('is_variant')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('attribute_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name');

            $table->unique(['attribute_id', 'locale']);
            $table->foreign('locale')->references('code')->on('languages')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });

        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('color_hex', 9)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['attribute_id', 'code']);
        });

        Schema::create('attribute_value_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_value_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('label');

            $table->unique(['attribute_value_id', 'locale']);
            $table->foreign('locale')->references('code')->on('languages')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_value_translations');
        Schema::dropIfExists('attribute_values');
        Schema::dropIfExists('attribute_translations');
        Schema::dropIfExists('attributes');
    }
};
