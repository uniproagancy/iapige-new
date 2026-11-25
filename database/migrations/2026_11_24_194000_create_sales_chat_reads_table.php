<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_chat_reads', function (Blueprint $table) {
            $table->foreignId('conversation_id')->constrained('sales_chat_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id');
            $table->primary(['conversation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_chat_reads');
    }
};
