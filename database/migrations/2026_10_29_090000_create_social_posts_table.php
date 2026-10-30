<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->string('page_id', 32);
            // Keep the audit record when the catalogue eventually removes a product.
            $table->unsignedBigInteger('product_id');
            $table->dateTime('scheduled_for');
            $table->date('scheduled_date');
            $table->string('status', 16)->default('draft');
            $table->string('theme', 24);
            $table->json('snapshot');
            $table->text('caption')->nullable();
            $table->string('image_path')->nullable();
            $table->string('post_id', 100)->nullable();
            $table->string('error', 160)->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['page_id', 'scheduled_for']);
            $table->unique(['page_id', 'product_id', 'scheduled_date']);
            $table->index(['status', 'scheduled_for']);
            $table->index(['page_id', 'product_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
