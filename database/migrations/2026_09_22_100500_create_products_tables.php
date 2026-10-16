<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 64)->unique();
            $table->decimal('price', 10, 2);
            $table->decimal('old_price', 10, 2)->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->string('status', 16)->default('draft');
            $table->boolean('is_new')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->decimal('rating', 2, 1)->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->unsignedInteger('sales_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index(['category_id', 'status']);
            $table->index(['brand_id', 'status']);
            $table->index('price');
        });

        Schema::create('product_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name');
            $table->string('slug');
            $table->string('summary')->nullable();
            $table->longText('description')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();

            $table->unique(['product_id', 'locale']);
            $table->unique(['locale', 'slug']);
            $table->foreign('locale')->references('code')->on('languages')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });

        Schema::create('attribute_value_product', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_value_id')->constrained()->cascadeOnDelete();

            $table->primary(['product_id', 'attribute_value_id']);
            $table->index('attribute_value_id');
        });

        Schema::create('product_specs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_key')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'attribute_id']);
        });

        Schema::create('product_spec_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_spec_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('value');

            $table->unique(['product_spec_id', 'locale']);
            $table->foreign('locale')->references('code')->on('languages')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_spec_translations');
        Schema::dropIfExists('product_specs');
        Schema::dropIfExists('attribute_value_product');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_translations');
        Schema::dropIfExists('products');
    }
};
