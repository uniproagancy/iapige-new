<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_chat_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->text('openai_key')->nullable();
            $table->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_chat_settings');
    }
};
