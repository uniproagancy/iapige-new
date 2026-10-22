<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /* The driver is gone, so any method still pointing at it would offer
           the shopper a payment that can no longer start. Take it offline
           rather than deleting it: existing orders still name the method. */
        DB::table('payment_methods')
            ->where('driver', 'credo-part-installment')
            ->update(['driver' => null, 'is_online' => false, 'is_active' => false]);
    }

    public function down(): void {}
};
