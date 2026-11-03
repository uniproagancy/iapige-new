<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_callbacks', function (Blueprint $table) {
            $table->id();
            $table->string('driver', 40);
            $table->string('method', 8);
            $table->string('path', 255);
            $table->json('query')->nullable();
            $table->json('headers')->nullable();
            $table->mediumText('body')->nullable();
            $table->json('payload')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome', 24)->nullable();
            $table->boolean('confirmed')->nullable();
            $table->string('status_before', 24)->nullable();
            $table->string('status_after', 24)->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
            $table->index(['driver', 'created_at']);
            $table->index('outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_callbacks');
    }
};
