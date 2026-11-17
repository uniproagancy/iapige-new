<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_certificates', function (Blueprint $table): void {
            $table->string('supplier_native_sku', 191)->nullable();
            $table->string('supplier_native_sku_source', 32)->nullable();
            $table->text('supplier_native_sku_reference')->nullable();
            $table->text('supplier_native_sku_reason')->nullable();
            $table->string('supplier_native_sku_identity_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('warranty_certificates', function (Blueprint $table): void {
            $table->dropColumn(['supplier_native_sku', 'supplier_native_sku_source', 'supplier_native_sku_reference',
                'supplier_native_sku_reason', 'supplier_native_sku_identity_sha256']);
        });
    }
};
