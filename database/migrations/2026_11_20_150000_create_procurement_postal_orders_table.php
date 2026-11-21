<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('procurement_postal_orders')) {
            $expected = ['id', 'order_id', 'selected', 'selected_at', 'selected_by',
                'picked_up_at', 'picked_up_by', 'picked_up_source_hash', 'uploaded_at', 'uploaded_by',
                'uploaded_source_hash', 'source_snapshot', 'version', 'created_by', 'updated_by',
                'history', 'created_at', 'updated_at'];
            $actual = Schema::getColumnListing('procurement_postal_orders');
            sort($expected);
            sort($actual);
            if ($actual !== $expected) {
                throw new RuntimeException('Unexpected existing private postal schema.');
            }

            return;
        }

        // No source foreign-key cascade: private history survives source deletion.
        Schema::create('procurement_postal_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->boolean('selected')->default(true)->index();
            $table->timestamp('selected_at')->nullable();
            $table->unsignedBigInteger('selected_by')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->unsignedBigInteger('picked_up_by')->nullable();
            $table->char('picked_up_source_hash', 64)->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->char('uploaded_source_hash', 64)->nullable();
            $table->json('source_snapshot')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->json('history')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_postal_orders');
    }
};
