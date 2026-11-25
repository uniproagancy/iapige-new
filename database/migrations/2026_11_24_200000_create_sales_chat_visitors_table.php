<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_chat_visitors', function (Blueprint $table) {
            $table->char('session_hash', 64)->primary();
            $table->string('section', 16);
            $table->string('product_slug', 190)->nullable();
            $table->timestamp('last_seen_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_chat_visitors');
    }
};
