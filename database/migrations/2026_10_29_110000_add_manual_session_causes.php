<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_sessions', function (Blueprint $table): void {
            $table->boolean('causes_manual_override')->default(false)->after('result');
        });

        Schema::create('medical_session_causes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('medical_session_id');
            $table->foreignId('medical_cause_definition_id');
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'medical_session_id', 'medical_cause_definition_id'], 'medical_session_causes_unique');
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'medical_session_id'])
                ->references(['organization_id', 'id'])
                ->on('medical_sessions')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'medical_cause_definition_id'])
                ->references(['organization_id', 'id'])
                ->on('medical_cause_definitions')
                ->restrictOnDelete();
            $table->index(['organization_id', 'client_id', 'medical_session_id']);
        });
    }

    public function down(): void
    {
        throw new LogicException('Manual session cause history is preserved by forward-only migrations.');
    }
};
