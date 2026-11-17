<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_attachment_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('medical_attachment_id');
            $table->string('channel', 32);
            $table->string('status', 32)->default('claimed');
            $table->string('idempotency_key', 180);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('provider_reference', 255)->nullable();
            $table->string('error_code', 120)->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('fallback_url_expires_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'idempotency_key'], 'medical_attachment_delivery_idempotency_unique');
            $table->unique(['organization_id', 'medical_attachment_id', 'channel'], 'medical_attachment_delivery_unique');
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'client_id', 'medical_attachment_id'])
                ->references(['organization_id', 'client_id', 'id'])
                ->on('medical_attachments')
                ->cascadeOnDelete();
            $table->index(['organization_id', 'client_id', 'created_at'], 'medical_attachment_delivery_history_index');
        });
    }

    public function down(): void
    {
        throw new LogicException('Medical Stage 1C audio delivery history is forward-only.');
    }
};
