<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Someone asking to be called back.
 *
 * It is the cheapest lead a shop gets — a person who wants to buy but has a
 * question — so it is kept as its own record rather than mailed to an inbox
 * where it can be missed.
 */
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

            // new | called | closed
            $table->string('status', 16)->default('new');
            $table->text('note')->nullable();          // what the operator found out

            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();

            // where they were when they asked, so the operator opens the right page
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
