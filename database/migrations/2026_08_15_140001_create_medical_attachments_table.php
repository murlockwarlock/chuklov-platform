<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_attachments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('attachment_type', 64);
            $table->string('disk', 64)->default('private');
            $table->string('storage_path', 500);
            $table->string('original_filename', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256_checksum', 64);
            $table->string('scan_status', 32)->default('pending');
            $table->jsonb('scan_result_metadata')->nullable();
            $table->timestampTz('scanned_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'uploaded_by_user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->restrictOnDelete();
            $table->index(['organization_id', 'client_id', 'created_at']);
            $table->index(['organization_id', 'scan_status']);
            $table->index(['organization_id', 'uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_attachments');
    }
};
