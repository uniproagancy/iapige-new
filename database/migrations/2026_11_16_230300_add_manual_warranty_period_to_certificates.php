<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_certificates', function (Blueprint $table): void {
            $table->unsignedInteger('manual_term_value')->nullable();
            $table->string('manual_term_unit', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('warranty_certificates', function (Blueprint $table): void {
            $table->dropColumn(['manual_term_value', 'manual_term_unit']);
        });
    }
};
