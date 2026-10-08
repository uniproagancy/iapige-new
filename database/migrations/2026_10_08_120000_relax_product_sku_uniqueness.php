<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sku becomes the barcode, so it can no longer be unique.
 *
 * A barcode identifies an article, not a listing: two colour variants of one
 * phone carry the same number, while the same article bought from two
 * suppliers carries it once. That is exactly what we want — one product
 * collecting an offer from every supplier that stocks it — but it means the
 * column can repeat.
 *
 * Identity has not been lost, only moved to where it already was:
 * product_offers keeps unique(supplier_id, external_id), which is the
 * supplier's own id in its own column, and that pair is what the importer
 * matches on before it ever looks at the sku.
 *
 * The plain index stays, because the cross-supplier lookup still reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['sku']);
            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['sku']);
            $table->unique('sku');
        });
    }
};
