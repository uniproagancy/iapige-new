<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The browser context an order was placed in, kept for the Purchase event.
 *
 * A card payment is confirmed by a bank callback or by payments:check, hours
 * later and with no browser in sight — but Meta needs the cookies, the address
 * and the user agent of the person who actually bought. They are taken while
 * the customer is still on the page and read back when the money lands.
 *
 * sent_at is what stops a resent bank callback from reporting a second sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_pixel_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();

            // shared with the browser's own Purchase, so Meta keeps only one
            $table->string('event_id', 64);

            $table->string('fbp')->nullable();
            $table->string('fbc')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('source_url', 500)->nullable();

            // the cookie banner's answer at the moment of the order
            $table->boolean('consented')->default(false);

            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_pixel_data');
    }
};
