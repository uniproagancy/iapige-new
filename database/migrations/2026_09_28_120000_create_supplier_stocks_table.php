<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 64);
            $table->unsignedInteger('quantity')->default(0);
            $table->decimal('cost_price', 10, 2)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'external_id']);
            $table->index(['supplier_id', 'quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_stocks');
    }
};
