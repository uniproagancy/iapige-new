<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-orders: a product with no stock that can still be bought, because it is
 * on its way. The order item keeps its own copy of the flag and the date, so a
 * finished order still tells the truth after the product itself goes on sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_preorder')->default(false)->after('stock');
            $table->date('release_date')->nullable()->after('is_preorder');

            // how much is taken up front; null means the shop-wide default
            $table->unsignedTinyInteger('prepay_percent')->nullable()->after('release_date');

            $table->index(['is_preorder', 'release_date']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('is_preorder')->default(false)->after('qty');
            $table->date('release_date')->nullable()->after('is_preorder');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['is_preorder', 'release_date']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_preorder', 'release_date']);
            $table->dropColumn(['is_preorder', 'release_date', 'prepay_percent']);
        });
    }
};