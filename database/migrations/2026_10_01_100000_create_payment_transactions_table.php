<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('driver', 32);
            $table->string('external_id')->nullable();
            $table->decimal('amount', 10, 2);
            $table->unsignedTinyInteger('months')->nullable();

            $table->string('status', 16)->default('pending');

            $table->json('request')->nullable();
            $table->json('response')->nullable();
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
