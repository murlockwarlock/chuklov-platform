<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_sessions', function (Blueprint $table): void {
            $table->unique(
                ['organization_id', 'client_id', 'id'],
                'medical_sessions_org_client_id_unique',
            );
        });

        Schema::table('medical_attachments', function (Blueprint $table): void {
            $table->unique(
                ['organization_id', 'client_id', 'id'],
                'medical_attachments_org_client_id_unique',
            );
        });

        Schema::create('medical_session_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('medical_session_id');
            $table->foreignId('medical_attachment_id');
            $table->timestampsTz();

            $table->foreign(['organization_id', 'client_id', 'medical_session_id'])
                ->references(['organization_id', 'client_id', 'id'])
                ->on('medical_sessions')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'client_id', 'medical_attachment_id'])
                ->references(['organization_id', 'client_id', 'id'])
                ->on('medical_attachments')
                ->restrictOnDelete();
            $table->unique(
                ['organization_id', 'medical_session_id', 'medical_attachment_id'],
                'medical_session_attachments_unique',
            );
            $table->index(
                ['organization_id', 'medical_session_id', 'id'],
                'medical_session_attachments_session_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_session_attachments');

        Schema::table('medical_attachments', function (Blueprint $table): void {
            $table->dropUnique('medical_attachments_org_client_id_unique');
        });

        Schema::table('medical_sessions', function (Blueprint $table): void {
            $table->dropUnique('medical_sessions_org_client_id_unique');
        });
    }
};
