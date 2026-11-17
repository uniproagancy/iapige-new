<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_policies', function (Blueprint $table): void {
            $table->string('start_basis', 24)->nullable()->default(null)->change();
            $table->text('review_notes')->nullable();
            $table->text('customer_reference')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('warranty_policies')->whereNull('start_basis')->exists()) {
            throw new RuntimeException('Complete pending warranty start dates before reverting this migration.');
        }
        Schema::table('warranty_policies', function (Blueprint $table): void {
            $table->string('start_basis', 24)->nullable(false)->default('delivery_date')->change();
            $table->dropColumn(['review_notes', 'customer_reference']);
        });
    }
};
