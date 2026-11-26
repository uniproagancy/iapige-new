<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_chat_visitor_days', function (Blueprint $table): void {
            $table->date('visited_on');
            $table->char('browser_hash', 64);
            $table->primary(['visited_on', 'browser_hash']);
        });

        Schema::create('sales_chat_visitor_history', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->dateTime('started_at_utc');
        });

        DB::table('sales_chat_visitor_history')->insert(['id' => 1, 'started_at_utc' => now('UTC')->toDateTimeString()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_chat_visitor_history');
        Schema::dropIfExists('sales_chat_visitor_days');
    }
};
