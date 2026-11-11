<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table) {
            $table->timestamp('cash_received_at')->nullable();
            $table->foreignId('cash_received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('cash_received_amount', 12, 2)->nullable();
        });
        Schema::table('orders', fn (Blueprint $table) => $table->string('payment_bank', 120)->nullable());
    }

    public function down(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_received_by');
            $table->dropColumn(['cash_received_at', 'cash_received_amount']);
        });
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('payment_bank'));
    }
};
