<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_consents', function (Blueprint $table): void {
            $table->foreignId('legal_document_id')
                ->nullable()
                ->after('client_id')
                ->constrained('legal_documents')
                ->restrictOnDelete();
            $table->index(['organization_id', 'client_id', 'legal_document_id']);
        });
    }

    public function down(): void
    {
        Schema::table('client_consents', function (Blueprint $table): void {
            $table->dropForeign(['legal_document_id']);
            $table->dropIndex(['organization_id', 'client_id', 'legal_document_id']);
            $table->dropColumn('legal_document_id');
        });
    }
};
