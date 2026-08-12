<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_documents', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
            $table->unique(['organization_id', 'id'], 'legal_documents_organization_id_id_unique');
        });

        Schema::table('client_consents', function (Blueprint $table): void {
            $table->dropForeign(['legal_document_id']);
            $table->foreign(['organization_id', 'legal_document_id'])
                ->references(['organization_id', 'id'])
                ->on('legal_documents')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_consents', function (Blueprint $table): void {
            $table->dropForeign(['organization_id', 'legal_document_id']);
            $table->foreign('legal_document_id')
                ->references('id')
                ->on('legal_documents')
                ->restrictOnDelete();
        });

        Schema::table('legal_documents', function (Blueprint $table): void {
            $table->dropUnique('legal_documents_organization_id_id_unique');
        });
    }
};
