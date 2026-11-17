<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_certificates', function (Blueprint $table): void {
            $table->string('sale_date_source', 32)->nullable();
            // Keep the historic reference even if the CRM event is later removed.
            $table->unsignedBigInteger('sale_date_source_event_id')->nullable();
            $table->text('sale_date_override_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('warranty_certificates', function (Blueprint $table): void {
            $table->dropColumn(['sale_date_source', 'sale_date_source_event_id', 'sale_date_override_reason']);
        });
    }
};
