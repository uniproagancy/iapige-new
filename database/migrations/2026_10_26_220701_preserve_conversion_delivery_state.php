<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_pixel_data', function (Blueprint $table) {
            $table->string('external_id', 191)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->string('action_source', 32)->nullable();
            $table->json('custom_data')->nullable();
            $table->json('user_data')->nullable();
            $table->timestamp('browser_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_pixel_data', function (Blueprint $table) {
            $table->dropColumn(['external_id', 'occurred_at', 'action_source', 'custom_data', 'user_data', 'browser_sent_at']);
        });
    }
};
