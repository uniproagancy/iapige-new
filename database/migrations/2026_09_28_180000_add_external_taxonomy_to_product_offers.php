<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The supplier's own category and brand names, kept on the offer.
 *
 * Without them a mapping made after the import could never be applied to the
 * products already in the shop — the only way back would be a full re-import.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_offers', function (Blueprint $table) {
            $table->string('external_category')->nullable()->after('external_id');
            $table->string('external_brand')->nullable()->after('external_category');

            $table->index(['supplier_id', 'external_category']);
        });
    }

    public function down(): void
    {
        Schema::table('product_offers', function (Blueprint $table) {
            $table->dropIndex(['supplier_id', 'external_category']);
            $table->dropColumn(['external_category', 'external_brand']);
        });
    }
};
