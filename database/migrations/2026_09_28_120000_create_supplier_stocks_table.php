<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a supplier says it holds, refreshed from its own stock feed.
 *
 * Some suppliers publish stock separately from product details (Alta: a SOAP
 * B2B price list plus a website). This table is that first half: it decides
 * which external ids are worth fetching at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 64);          // their sku / barcode
            $table->unsignedInteger('quantity')->default(0);
            $table->decimal('cost_price', 10, 2)->nullable();   // when the feed carries prices
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'external_id']);
            $table->index(['supplier_id', 'quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_stocks');
    }
};
