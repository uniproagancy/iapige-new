<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('callback_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('phone', 32);
            $table->text('comment')->nullable();

            $table->string('status', 16)->default('new');
            $table->text('note')->nullable();

            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();

            $table->string('page', 255)->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('callback_requests');
    }
};
