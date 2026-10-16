<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('type', 16)->default('deal');
            $table->string('image')->nullable();
            $table->string('url')->nullable();
            $table->string('badge', 24)->nullable();
            $table->string('badge_color', 9)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
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
            $table->string('cta')->nullable();

            $table->unique(['promotion_id', 'locale']);
            $table->foreign('locale')->references('code')->on('languages')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });

        Schema::create('promotion_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->decimal('promo_price', 10, 2)->nullable();
            $table->unsignedTinyInteger('discount_percent')->nullable();

            $table->unsignedInteger('stock_limit')->nullable();
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
