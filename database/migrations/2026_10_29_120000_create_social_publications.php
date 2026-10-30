<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_publications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('social_post_id')->nullable()->index();
            $table->string('origin', 16)->default('automatic');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->char('request_key', 64);
            $table->string('channel', 24);
            $table->string('account_id', 32)->nullable();
            $table->dateTime('scheduled_for');
            $table->string('status', 16)->default('draft');
            $table->string('phase', 24)->nullable();
            $table->json('snapshot')->nullable();
            $table->text('caption')->nullable();
            $table->string('image_path')->nullable();
            $table->string('mime_type', 32)->nullable();
            $table->char('media_key', 64)->nullable()->unique();
            $table->dateTime('media_expires_at')->nullable();
            $table->string('container_id', 100)->nullable();
            $table->string('media_id', 100)->nullable();
            $table->string('external_id', 100)->nullable();
            $table->string('permalink', 500)->nullable();
            $table->string('error', 80)->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['request_key', 'channel']);
            $table->index(['origin', 'status', 'scheduled_for']);
            $table->index(['product_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_publications');
    }
};
