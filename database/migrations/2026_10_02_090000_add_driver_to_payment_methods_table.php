<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which payment driver each method uses.
 *
 * Until now the checkout guessed from the method's code, so a method named
 * anything else fell through to a driver that does not exist. A method should
 * say what processes it rather than be recognised by its name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_methods', 'is_online')) {
                $table->boolean('is_online')->default(false)->after('code');
            }

            // these may already exist from an earlier attempt
            if (! Schema::hasColumn('payment_methods', 'driver')) {
                $table->string('driver', 32)->nullable();
            }

            if (! Schema::hasColumn('payment_methods', 'installment_type')) {
                $table->string('installment_type', 16)->nullable();
            }
        });

        // whatever the codes happen to be, instalments are recognisable by name
        DB::table('payment_methods')
            ->where(function ($q) {
                $q->where('code', 'like', '%installment%')
                    ->orWhere('code', 'like', '%ganvadeba%')
                    ->orWhere('code', 'like', '%განვადება%');
            })
            ->update([
                'is_online'        => true,
                'driver'           => 'bog-installment',
                'installment_type' => 'STANDARD',
            ]);

        DB::table('payment_methods')
            ->where('code', 'like', '%zero%')
            ->update(['installment_type' => 'ZERO']);

        // a method marked online with no driver fails at the worst moment
        DB::table('payment_methods')
            ->whereNull('driver')
            ->update(['is_online' => false]);
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn(['driver', 'installment_type']);
        });
    }
};
