<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_eval_suites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('key', 80);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('capability', 80);
            $table->foreignId('prompt_id')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'key']);
        });

        Schema::create('ai_eval_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('eval_suite_id');
            $table->string('name', 200);
            $table->boolean('is_synthetic')->default(false);
            $table->boolean('is_deidentified')->default(false);
            $table->json('test_inputs');
            $table->json('expected_output_schema')->nullable();
            $table->json('expected_assertions');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'eval_suite_id'])
                ->references(['organization_id', 'id'])->on('ai_eval_suites')->cascadeOnDelete();
        });

        Schema::create('ai_eval_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('eval_suite_id');
            $table->foreignId('prompt_version_id');
            $table->string('provider', 64);
            $table->string('model', 120);
            $table->unsignedInteger('total_cases')->default(0);
            $table->unsignedInteger('passed_cases')->default(0);
            $table->unsignedInteger('failed_cases')->default(0);
            $table->json('results_payload');
            $table->foreignId('executed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'eval_suite_id'])
                ->references(['organization_id', 'id'])->on('ai_eval_suites')->cascadeOnDelete();
            $table->foreign(['organization_id', 'prompt_version_id'])
                ->references(['organization_id', 'id'])->on('ai_prompt_versions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_eval_runs');
        Schema::dropIfExists('ai_eval_cases');
        Schema::dropIfExists('ai_eval_suites');
    }
};
