<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attributes describe products: RAM, screen, colour, storage, processor …
 *
 *  type      select → fixed values (attribute_values), usable as a filter
 *            color  → select with a swatch (hex on the value)
 *            text   → free text per product (product_specs), e.g. "Ryzen 9 7940HS"
 *  is_filterable → shown in the catalog sidebar
 *  is_variant    → chosen on the product page (colour, storage) — used by the variants batch
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();           // ram, screen, color, storage, cpu
            $table->string('type', 16)->default('select');  // select | color | text
            $table->string('unit', 16)->nullable();         // GB, ″, კგ
            $table->boolean('is_filterable')->default(false);
            $table->boolean('is_variant')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('attribute_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name');                         // ოპერატიული / RAM

            $table->unique(['attribute_id', 'locale']);
            $table->foreign('locale')->references('code')->on('languages')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });

        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);                     // 16gb, oled, graphite — stable, used in filter URLs
            $table->string('color_hex', 9)->nullable();     // for type=color
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['attribute_id', 'code']);
        });

        Schema::create('attribute_value_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_value_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('label');                        // ვერცხლისფერი / Silver

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
