<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->date('delivery_date');
            $table->foreignId('courier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('active');
            $table->boolean('is_eligible')->default(false);
            $table->string('name');
            $table->string('phone', 32);
            $table->string('city')->nullable();
            $table->string('address', 1000)->nullable();
            $table->text('customer_comment')->nullable();
            $table->string('payment', 191);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('shipping', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->boolean('is_paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['delivery_date', 'status', 'courier_id']);
        });

        Schema::create('order_delivery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 191)->nullable();
            $table->string('name');
            $table->unsignedInteger('qty');
            $table->decimal('price', 12, 2);
            $table->decimal('sum', 12, 2);
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_name')->nullable();
            $table->string('supplier_sku', 191)->nullable();
            $table->string('warehouse')->nullable();
            $table->string('pickup_address', 1000)->nullable();
            $table->boolean('is_manual')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['order_delivery_id', 'order_item_id']);
            $table->index(['order_delivery_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_delivery_items');
        Schema::dropIfExists('order_deliveries');
    }
};
