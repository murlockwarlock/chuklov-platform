<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_avatar_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('medical_attachment_id');
            $table->string('source', 32);
            $table->timestampsTz();
            $table->unique(['organization_id', 'client_id'], 'client_avatar_profile_unique');
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'client_id', 'medical_attachment_id'])
                ->references(['organization_id', 'client_id', 'id'])
                ->on('medical_attachments')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        throw new LogicException('Medical Stage 1C avatar history is forward-only.');
    }
};
