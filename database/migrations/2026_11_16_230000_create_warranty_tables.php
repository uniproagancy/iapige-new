<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('family_uuid');
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('draft');
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            foreach (['brand', 'category', 'product'] as $scope) {
                $table->foreignId($scope.'_id')->nullable()->constrained()->restrictOnDelete();
            }
            $table->string('name');
            $table->unsignedInteger('term_value')->nullable();
            $table->string('term_unit', 12)->nullable();
            foreach (['term_text', 'component_terms', 'issuer_name', 'issuer_address', 'issuer_contact', 'territory', 'coverage', 'conditions', 'claim_instructions', 'service_name', 'service_address', 'service_phone', 'service_email', 'source_url', 'source_reference', 'original_not_required_reason'] as $field) {
                $table->text($field)->nullable();
            }
            $table->string('start_basis', 24)->default('delivery_date');
            $table->boolean('source_confirmed')->default(false);
            $table->boolean('requires_original')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            foreach (['created_by', 'updated_by', 'approved_by'] as $field) {
                $table->foreignId($field)->nullable()->constrained('users')->nullOnDelete();
            }
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['family_uuid', 'version']);
            $table->index(['supplier_id', 'status']);
        });

        Schema::create('warranty_certificates', function (Blueprint $table) {
            $table->id();
            $table->string('number', 64)->unique();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('previous_certificate_id')->nullable()->constrained('warranty_certificates')->restrictOnDelete();
            foreach (['order', 'order_item', 'order_delivery_item', 'product', 'supplier'] as $reference) {
                $table->foreignId($reference.'_id')->nullable()->constrained()->nullOnDelete();
            }
            $table->foreignId('policy_id')->nullable()->constrained('warranty_policies')->restrictOnDelete();
            $table->unsignedInteger('unit_index');
            $table->string('status', 20)->default('draft');
            $table->text('customer_name')->nullable();
            $table->text('customer_phone')->nullable();
            $table->string('serial_number', 191)->nullable();
            $table->string('serial_fingerprint', 64)->nullable()->index();
            $table->text('not_serialized_reason')->nullable();
            foreach (['sale_date', 'delivery_date', 'manufacturer_date'] as $field) {
                $table->date($field)->nullable();
            }
            $table->boolean('actual_supplier_confirmed')->default(false);
            $table->boolean('delivery_date_confirmed')->default(false);
            $table->text('delivery_date_reason')->nullable();
            $table->text('manufacturer_date_proof')->nullable();
            $table->longText('snapshot')->nullable();
            $table->string('snapshot_sha256', 64)->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->string('pdf_sha256', 64)->nullable();
            foreach (['created_by', 'updated_by', 'issued_by', 'revoked_by'] as $field) {
                $table->foreignId($field)->nullable()->constrained('users')->nullOnDelete();
            }
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();
            $table->unique(['order_delivery_item_id', 'unit_index', 'revision'], 'warranty_unit_revision_unique');
            $table->index(['order_id', 'status']);
            $table->index(['supplier_id', 'product_id', 'serial_number'], 'warranty_serial_lookup');
        });

        Schema::create('warranty_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->nullable()->constrained('warranty_policies')->restrictOnDelete();
            $table->foreignId('certificate_id')->nullable()->constrained('warranty_certificates')->restrictOnDelete();
            $table->string('role', 32);
            $table->string('label');
            $table->string('original_name');
            $table->string('mime_type', 128);
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('sha256', 64);
            $table->unsignedBigInteger('bytes');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('warranty_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_id')->nullable()->constrained('warranty_certificates')->restrictOnDelete();
            $table->foreignId('policy_id')->nullable()->constrained('warranty_policies')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 64);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_events');
        Schema::dropIfExists('warranty_documents');
        Schema::dropIfExists('warranty_certificates');
        Schema::dropIfExists('warranty_policies');
    }
};
