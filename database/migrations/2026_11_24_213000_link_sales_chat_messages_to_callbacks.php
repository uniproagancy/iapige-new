<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_chat_messages', function (Blueprint $table): void {
            $table->foreignId('callback_request_id')->nullable()->constrained('callback_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_chat_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('callback_request_id');
        });
    }
};
