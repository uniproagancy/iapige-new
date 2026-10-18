<?php

use App\Services\Payments\Drivers\BogInstallment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! config('bog.installment.secret_key')) {
            return;
        }

        DB::table('payment_methods')
            ->where('code', 'like', '%split%')
            ->orWhere('code', 'like', '%nawil%')
            ->update([
                'driver' => 'bog-installment',
                'installment_type' => BogInstallment::ZERO,
                'is_online' => true,
            ]);
    }

    public function down(): void
    {
        DB::table('payment_methods')
            ->where('code', 'like', '%split%')
            ->update(['driver' => null, 'is_online' => false]);
    }
};
