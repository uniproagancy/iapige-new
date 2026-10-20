<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /* Orders are left exactly as they were: they are what the customer
           agreed to and what the bank settled. Only the shelf is rounded. */
        DB::table('products')->whereNotNull('price')->update([
            'price' => DB::raw('round(price)'),
        ]);

        DB::table('products')->whereNotNull('old_price')->update([
            'old_price' => DB::raw('round(old_price)'),
        ]);

        DB::table('promotion_product')->whereNotNull('promo_price')->update([
            'promo_price' => DB::raw('round(promo_price)'),
        ]);
    }

    public function down(): void {}
};
