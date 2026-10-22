<?php

use App\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 | The shop works in four states. The ones that used to sit between them all
 | said the same thing to the people using the admin — somebody has picked this
 | up — so they fold onto "viewed", and a return folds onto "cancelled".
 */
return new class extends Migration
{
    protected const SETTLES = [
        'confirmed' => Order::VIEWED,
        'packed' => Order::VIEWED,
        'shipped' => Order::VIEWED,
        'returned' => Order::CANCELLED,
    ];

    public function up(): void
    {
        foreach (self::SETTLES as $was => $now) {
            DB::table('orders')->where('status', $was)->update(['status' => $now]);

            /* The order's own history keeps the word it was filed under, but
               the last entry has to agree with the order itself. */
            DB::table('order_events')
                ->where('type', 'status')
                ->where('to', $was)
                ->update(['to' => $now]);
        }
    }

    public function down(): void
    {
        /* Which of the three "viewed" came from cannot be known any more. */
    }
};
