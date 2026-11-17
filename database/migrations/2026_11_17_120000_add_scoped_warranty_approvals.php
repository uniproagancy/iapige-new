<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Warranty-only additions. No constraint is added to the live order/deletion workflow.
        if (! Schema::hasColumn('warranty_policies', 'order_delivery_item_id')) {
            Schema::table('warranty_policies', fn (Blueprint $table) => $table->unsignedBigInteger('order_delivery_item_id')->nullable()->index());
        }
        if (! Schema::hasColumn('warranty_policies', 'approval_mode')) {
            Schema::table('warranty_policies', fn (Blueprint $table) => $table->string('approval_mode', 32)->nullable());
        }
        if (! Schema::hasColumn('warranty_policies', 'approval_context')) {
            Schema::table('warranty_policies', fn (Blueprint $table) => $table->json('approval_context')->nullable());
        }
    }

    public function down(): void
    {
        // Keep approval provenance when rolling application code back.
        throw new LogicException('Scoped warranty approvals are forward-only; preserve issued document provenance.');
    }
};
