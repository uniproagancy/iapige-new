<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('callback_requests', function (Blueprint $table) {
            $table->uuid('submission_key')->nullable()->unique();
            $table->char('submission_owner', 64)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->string('product_name')->nullable();
            $table->string('product_sku', 191)->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->decimal('total', 10, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('payment_category', 16)->nullable();
            $table->string('payment_method', 40)->nullable();
            $table->string('external_id', 191)->nullable();
            $table->string('checkout_event_id', 64)->nullable();
            $table->timestamp('checkout_started_at')->nullable();
            $table->json('checkout_data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('callback_requests', function (Blueprint $table) {
            $table->dropUnique(['submission_key']);
            $table->dropColumn(['submission_key', 'submission_owner', 'request_hash', 'product_name',
                'product_sku', 'quantity', 'unit_price', 'total', 'currency', 'payment_category',
                'payment_method', 'external_id', 'checkout_event_id', 'checkout_started_at', 'checkout_data']);
        });
    }
};
