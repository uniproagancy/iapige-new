<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No cascading source foreign keys: a removed order must retain its supplier cancellation task.
        if (! $this->exists('procurement_items', ['id', 'order_delivery_item_id', 'status', 'note', 'native_sku',
            'native_identity_hash', 'source_snapshot', 'last_sent_snapshot', 'issue_qty', 'sent_version',
            'withdrawn_sent_version', 'version', 'created_by', 'updated_by', 'created_at', 'updated_at'])) {
            Schema::create('procurement_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_delivery_item_id')->unique();
                $table->string('status', 20)->default('unsent')->index();
                $table->text('note')->nullable();
                $table->string('native_sku', 191)->nullable();
                $table->char('native_identity_hash', 64)->nullable();
                $table->json('source_snapshot')->nullable();
                $table->json('last_sent_snapshot')->nullable();
                $table->unsignedInteger('issue_qty')->nullable();
                $table->unsignedInteger('sent_version')->default(0);
                $table->unsignedInteger('withdrawn_sent_version')->nullable();
                $table->unsignedInteger('version')->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (! $this->exists('procurement_tasks', ['id', 'task_key', 'procurement_item_id', 'snapshot',
            'acknowledged_at', 'acknowledged_by', 'created_at', 'updated_at'])) {
            Schema::create('procurement_tasks', function (Blueprint $table) {
                $table->id();
                $table->char('task_key', 64)->unique();
                $table->unsignedBigInteger('procurement_item_id')->index();
                $table->json('snapshot');
                $table->timestamp('acknowledged_at')->nullable()->index();
                $table->unsignedBigInteger('acknowledged_by')->nullable();
                $table->timestamps();
            });
        }

        if (! $this->exists('procurement_events', ['id', 'procurement_item_id', 'kind', 'actor_id', 'payload', 'created_at'])) {
            Schema::create('procurement_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('procurement_item_id')->index();
                $table->string('kind', 40);
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->json('payload');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    private function exists(string $table, array $expected): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }
        $actual = Schema::getColumnListing($table);
        sort($expected);
        sort($actual);
        if ($actual !== $expected) {
            throw new RuntimeException('Unexpected existing procurement schema: '.$table);
        }

        return true;
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_events');
        Schema::dropIfExists('procurement_tasks');
        Schema::dropIfExists('procurement_items');
    }
};
