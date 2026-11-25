<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->char('session_hash', 64)->unique();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handoff_requested_at')->nullable();
            $table->foreignId('callback_request_id')->nullable()->unique()->constrained('callback_requests')->nullOnDelete();
            $table->timestamp('contact_consent_at')->nullable();
            $table->timestamps();
            $table->index(['updated_at', 'id']);
        });

        Schema::create('sales_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('sales_chat_conversations')->cascadeOnDelete();
            $table->string('role', 16);
            $table->text('body');
            $table->json('cards')->nullable();
            $table->foreignId('staff_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_message_id')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'id']);
            $table->unique(['conversation_id', 'client_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_chat_messages');
        Schema::dropIfExists('sales_chat_conversations');
    }
};
