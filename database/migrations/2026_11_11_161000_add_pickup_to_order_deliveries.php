<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table) {
            $table->timestamp('picked_up_at')->nullable();
            $table->foreignId('picked_up_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('picked_up_by');
            $table->dropColumn('picked_up_at');
        });
    }
};
