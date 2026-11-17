<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_certificates', function (Blueprint $table): void {
            $table->char('access_token_hash', 64)->nullable()->unique();
            $table->text('access_token')->nullable();
            $table->timestamp('public_enabled_at')->nullable();
            $table->timestamp('last_sms_at')->nullable();
        });

        Schema::create('warranty_sms_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warranty_certificate_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 100);
            $table->string('status', 24)->default('pending');
            $table->text('recipient');
            $table->text('message');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['warranty_certificate_id', 'idempotency_key'], 'warranty_sms_idempotency');
            $table->index(['warranty_certificate_id', 'status']);
        });

        Schema::create('warranty_agent_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique();
            $table->json('scopes');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_agent_keys');
        Schema::dropIfExists('warranty_sms_messages');
        Schema::table('warranty_certificates', function (Blueprint $table): void {
            $table->dropUnique(['access_token_hash']);
            $table->dropColumn(['access_token_hash', 'access_token', 'public_enabled_at', 'last_sms_at']);
        });
    }
};
