<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slides', function (Blueprint $table) {
            $table->id();
            $table->string('image')->nullable();
            $table->string('url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
            $table->index('sort_order');
        });

        Schema::create('slide_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slide_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);

            $table->string('dates')->nullable();
            $table->string('title')->nullable();
            $table->string('sub')->nullable();
            $table->string('cta')->nullable();

            $table->unique(['slide_id', 'locale']);
            $table->foreign('locale')->references('code')->on('languages')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slide_translations');
        Schema::dropIfExists('slides');
    }
};
