<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table) {
            $table->string('payment_bank', 120)->nullable()->after('payment');
        });
    }

    public function down(): void
    {
        Schema::table('order_deliveries', fn (Blueprint $table) => $table->dropColumn('payment_bank'));
    }
};
