<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_complaint_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 255);
            $table->string('group_name', 120)->nullable();
            $table->json('synonyms')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'is_active', 'sort_order']);
        });

        foreach (['medical_profile_pains', 'medical_session_pains'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->unsignedBigInteger('medical_complaint_definition_id')->nullable()->after('patient_profile_id');
                $table->foreign(['organization_id', 'medical_complaint_definition_id'], $tableName.'_complaint_definition_fk')
                    ->references(['organization_id', 'id'])
                    ->on('medical_complaint_definitions')
                    ->restrictOnDelete();
                $table->index(['organization_id', 'medical_complaint_definition_id'], $tableName.'_complaint_definition_index');
            });
        }
    }

    public function down(): void
    {
        throw new LogicException('Medical complaint definitions and references are retained by forward-only migrations.');
    }
};
