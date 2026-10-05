<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which payment methods owe the customer an invoice.
 *
 * A bank transfer cannot be completed on the site: the customer needs our
 * account details and a reference, in writing, before they can pay at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_methods', 'sends_invoice')) {
                $table->boolean('sends_invoice')->default(false);
            }
        });

        DB::table('payment_methods')
            ->where('code', 'like', '%transfer%')
            ->orWhere('code', 'like', '%invoice%')
            ->update(['sends_invoice' => true]);
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('sends_invoice');
        });
    }
};
