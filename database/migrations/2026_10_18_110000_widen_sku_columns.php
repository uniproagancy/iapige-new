<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('sku', 191)->change();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('sku', 191)->nullable()->change();
        });

        Schema::table('product_offers', function (Blueprint $table) {
            $table->string('external_id', 191)->change();
        });

        Schema::table('supplier_stocks', function (Blueprint $table) {
            $table->string('external_id', 191)->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('sku', 64)->nullable()->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('sku', 64)->change();
        });
    }
};
