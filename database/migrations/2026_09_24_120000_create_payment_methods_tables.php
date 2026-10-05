<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment methods the checkout offers. `driver` stays null until the bank
 * integration lands — such a method simply creates the order and waits for a
 * manual confirmation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();            // bog_card, tbc_installment …
            $table->string('driver')->nullable();            // App\Services\Payments\Drivers\BogDriver
            $table->string('logo')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_online')->default(true);     // redirects to the bank
            $table->decimal('min_total', 10, 2)->nullable(); // instalments usually have a floor
            $table->decimal('max_total', 10, 2)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('config')->nullable();              // keys and endpoints, per method
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('payment_method_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_method_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name');
            $table->string('note')->nullable();              // "approved in 3 minutes"

            $table->unique(['payment_method_id', 'locale']);
            $table->foreign('locale')->references('code')->on('languages')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_method_translations');
        Schema::dropIfExists('payment_methods');
    }
};