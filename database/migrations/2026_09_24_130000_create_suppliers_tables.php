<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers and their offers.
 *
 * A product exists once in the catalogue; every supplier that carries it adds
 * an offer. The product's own price and stock are derived from the offers, so
 * the same laptop never shows up twice in the listing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();              // zoommer, alta …
            $table->string('name');
            $table->string('driver');                          // App\Services\Import\Drivers\…
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('priority')->default(100); // lower wins on equal price
            $table->json('config')->nullable();                // endpoints, tokens, id range
            $table->json('markup')->nullable();                // [{"up_to":100,"add":30}, …]
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 64);
            $table->decimal('cost_price', 10, 2);
            $table->decimal('old_cost_price', 10, 2)->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'external_id']);
            $table->index(['product_id', 'cost_price']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cost_price', 10, 2)->nullable()->after('old_price');
            $table->boolean('price_lock')->default(false)->after('cost_price');    // a hand-set price wins
            $table->boolean('taxonomy_lock')->default(false)->after('price_lock'); // a hand-set category wins
            $table->timestamp('synced_at')->nullable()->after('published_at');

            $table->index('synced_at');
        });

        /* Their names → ours. Unknown names are parked here for the admin to map. */
        Schema::create('supplier_category_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('external_name');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();

            $table->unique(['supplier_id', 'external_name']);
        });

        Schema::create('supplier_attribute_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('external_name');
            $table->foreignId('attribute_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();

            $table->unique(['supplier_id', 'external_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_attribute_map');
        Schema::dropIfExists('supplier_category_map');

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['synced_at']);
            $table->dropColumn(['cost_price', 'price_lock', 'taxonomy_lock', 'synced_at']);
        });

        Schema::dropIfExists('product_offers');
        Schema::dropIfExists('suppliers');
    }
};
