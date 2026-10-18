<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected const LOGOS = [
        'bog_card' => '/img/payments/bog-1.png',
        'bog_installment' => '/img/payments/bog-1.png',
        'bog_split' => '/img/payments/bog-2.png',
        'tbc_installment' => '/img/payments/tbc-1.png',
        'credo_installment' => '/img/payments/credo-1.png',
    ];

    public function up(): void
    {
        foreach (self::LOGOS as $code => $logo) {
            DB::table('payment_methods')->where('code', $code)->update(['logo' => $logo]);
        }

        DB::table('payment_methods')
            ->where('logo', 'like', '/img/pay/%')
            ->update(['logo' => null]);
    }

    public function down(): void {}
};
