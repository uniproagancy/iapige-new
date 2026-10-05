<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hand-picked campaigns: "deal of the week", a seasonal sale, a bundle offer.
 *
 * A campaign carries its own price per product, so a discount never touches
 * products.price — when the campaign ends, the catalogue price is simply
 * itself again, with nothing to restore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();            // week-deal, black-friday
            $table->string('type', 16)->default('deal');     // deal | banner | bundle
            $table->string('image')->nullable();             // banner artwork
            $table->string('url')->nullable();               // where the banner leads
            $table->string('badge', 24)->nullable();         // "-20%", "NEW"
            $table->string('badge_color', 9)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();      // null = already running
            $table->timestamp('ends_at')->nullable();        // null = no end
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
            $table->index(['type', 'sort_order']);
        });

        Schema::create('promotion_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('cta')->nullable();               // button label

            $table->unique(['promotion_id', 'locale']);
            $table->foreign('locale')->references('code')->on('languages')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });

        Schema::create('promotion_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // exactly one of these: a fixed price, or a percentage off the current one
            $table->decimal('promo_price', 10, 2)->nullable();
            $table->unsignedTinyInteger('discount_percent')->nullable();

            $table->unsignedInteger('stock_limit')->nullable();  // how many go at this price
            $table->unsignedInteger('sold')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['promotion_id', 'product_id']);
            $table->index(['product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_product');
        Schema::dropIfExists('promotion_translations');
        Schema::dropIfExists('promotions');
    }
};