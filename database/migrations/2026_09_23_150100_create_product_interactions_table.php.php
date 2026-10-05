<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only log: what was added to a cart or wishlist and what was taken
 * back out. Rows are never updated, so the dashboard can ask questions like
 * "which products get added and then dropped most often".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_id', 100)->nullable();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('action', 24);                    // cart_add | cart_remove | wish_add | wish_remove
            $table->unsignedInteger('qty')->default(1);
            $table->decimal('price', 10, 2)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
            $table->index(['product_id', 'action']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_interactions');
    }
};