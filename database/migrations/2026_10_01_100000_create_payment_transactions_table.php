<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every attempt to pay for an order, successful or not.
 *
 * An order has one status; a payment has a history. A customer who tried the
 * card twice and then chose instalments leaves three rows here, and only the
 * last one matters — but when the bank disputes something months later, the
 * other two are the answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('driver', 32);                  // bog-installment, bog-card, tbc-card
            $table->string('external_id')->nullable();     // the bank's own id for this attempt
            $table->decimal('amount', 10, 2);
            $table->unsignedTinyInteger('months')->nullable();

            // pending | success | failed | cancelled | expired
            $table->string('status', 16)->default('pending');

            $table->json('request')->nullable();           // what we sent
            $table->json('response')->nullable();          // what came back, including callbacks
            $table->string('redirect_url', 2048)->nullable();

            $table->timestamp('checked_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['driver', 'status']);
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
