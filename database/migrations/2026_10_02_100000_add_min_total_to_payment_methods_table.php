<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The floor each payment method will accept.
 *
 * Every lender sets its own: below it the application is refused, or the
 * calculator opens empty. Keeping the figure beside the method means a changed
 * contract is a changed row rather than a changed release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_methods', 'min_total')) {
                $table->decimal('min_total', 10, 2)->nullable();
            }

            if (! Schema::hasColumn('payment_methods', 'max_total')) {
                $table->decimal('max_total', 10, 2)->nullable();
            }
        });

        foreach ([
            'bog_installment'   => 100,
            'bog_split'         => 100,
            'tbc_installment'   => 150,
            'credo_installment' => 150,
        ] as $code => $minimum) {
            DB::table('payment_methods')->where('code', $code)->update(['min_total' => $minimum]);
        }
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn(['min_total', 'max_total']);
        });
    }
};
