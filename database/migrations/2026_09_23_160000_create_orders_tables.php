<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();              // ELIO-260923-0042
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cart_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('new');        // new | confirmed | shipped | done | cancelled

            // who
            $table->string('name');
            $table->string('phone', 32);
            $table->string('email')->nullable();

            // where
            $table->string('delivery', 16)->default('courier');  // courier | pickup
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->text('comment')->nullable();

            // how
            $table->string('payment', 16)->default('cash');      // cash | card | installment

            // money — snapshot, never recalculated later
            $table->decimal('subtotal', 10, 2);
            $table->decimal('shipping', 10, 2)->default(0);
            $table->decimal('total', 10, 2);

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 64)->nullable();
            $table->string('name');                              // name at the time of ordering
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('qty');
            $table->decimal('sum', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};