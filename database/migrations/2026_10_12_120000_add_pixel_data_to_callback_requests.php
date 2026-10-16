<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('callback_requests', function (Blueprint $table) {

            $table->string('lead_event_id', 64)->nullable()->after('note');

            $table->string('fbp')->nullable()->after('lead_event_id');
            $table->string('fbc')->nullable()->after('fbp');
            $table->string('ip', 45)->nullable()->after('fbc');
            $table->text('user_agent')->nullable()->after('ip');
            $table->string('source_url', 500)->nullable()->after('user_agent');

            $table->boolean('consented')->default(false)->after('source_url');

            $table->foreignId('order_id')->nullable()->after('consented')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('callback_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
            $table->dropColumn([
                'lead_event_id', 'fbp', 'fbc', 'ip', 'user_agent', 'source_url', 'consented',
            ]);
        });
    }
};
