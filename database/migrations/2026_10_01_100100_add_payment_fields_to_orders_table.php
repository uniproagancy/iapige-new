<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the order is being paid, kept beside the order itself.
 *
 * payment_status is deliberately separate from status: an order can be paid
 * and not yet packed, or delivered and still unpaid on a cash sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'is_paid')) {
                $table->boolean('is_paid')->default(false)->after('total');
            }

            if (! Schema::hasColumn('orders', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('is_paid');
            }

            // pending | paid | failed | refunded
            $table->string('payment_status', 16)->default('pending')->after('paid_at');
            $table->unsignedTinyInteger('installment_months')->nullable()->after('payment_status');

            $table->index('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['payment_status']);
            $table->dropColumn(['payment_status', 'installment_months']);
        });
    }
};
