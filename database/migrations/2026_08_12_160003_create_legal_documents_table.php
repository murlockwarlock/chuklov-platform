<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('document_type', 64);
            $table->string('purpose', 120);
            $table->string('locale', 10);
            $table->enum('management_mode', ['platform_managed', 'organization_managed']);
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->string('version', 64);
            $table->longText('content');
            $table->boolean('is_required')->default(true);
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'document_type', 'locale', 'version']);
            $table->index(['organization_id', 'document_type', 'locale', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};
