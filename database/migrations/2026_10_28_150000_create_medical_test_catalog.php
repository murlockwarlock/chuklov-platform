<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_test_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('key', 120);
            $table->string('name', 255);
            $table->string('section', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'key']);
            $table->index(['organization_id', 'is_active', 'sort_order']);
        });

        Schema::create('medical_test_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('medical_test_definition_id');
            $table->string('key', 120);
            $table->string('label', 255);
            $table->boolean('is_abnormal')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'medical_test_definition_id', 'key'], 'medical_test_values_key_unique');
            $table->foreign(['organization_id', 'medical_test_definition_id'])
                ->references(['organization_id', 'id'])
                ->on('medical_test_definitions')
                ->cascadeOnDelete();
            $table->index(['organization_id', 'medical_test_definition_id', 'is_active', 'sort_order']);
        });

        Schema::create('medical_cause_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 255);
            $table->string('group_name', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'is_active', 'sort_order']);
        });

        Schema::create('medical_session_test_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('medical_session_id');
            $table->foreignId('medical_test_definition_id');
            $table->foreignId('before_value_id')->nullable();
            $table->foreignId('after_value_id')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'medical_session_id', 'medical_test_definition_id'], 'medical_session_tests_unique');
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'medical_session_id'])
                ->references(['organization_id', 'id'])
                ->on('medical_sessions')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'medical_test_definition_id'])
                ->references(['organization_id', 'id'])
                ->on('medical_test_definitions')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'before_value_id'])
                ->references(['organization_id', 'id'])
                ->on('medical_test_values')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'after_value_id'])
                ->references(['organization_id', 'id'])
                ->on('medical_test_values')
                ->restrictOnDelete();
            $table->index(['organization_id', 'client_id', 'medical_session_id']);
        });

        Schema::create('medical_session_test_causes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('medical_session_test_result_id');
            $table->foreignId('medical_cause_definition_id');
            $table->timestampsTz();
            $table->unique(['organization_id', 'medical_session_test_result_id', 'medical_cause_definition_id'], 'medical_session_test_causes_unique');
            $table->foreign(['organization_id', 'medical_session_test_result_id'])
                ->references(['organization_id', 'id'])
                ->on('medical_session_test_results')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'medical_cause_definition_id'])
                ->references(['organization_id', 'id'])
                ->on('medical_cause_definitions')
                ->restrictOnDelete();
        });

        Schema::create('medical_session_root_causes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('medical_session_id');
            $table->foreignId('medical_cause_definition_id')->nullable();
            $table->text('free_text')->nullable();
            $table->unsignedInteger('encryption_key_version')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
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
            $table->index(['organization_id', 'medical_session_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        throw new LogicException('Medical test and cause history is preserved by forward-only migrations.');
    }
};
