<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('survey_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('definition_key', 120);
            $table->string('title', 200);
            $table->string('title_en', 200)->nullable();
            $table->text('description')->nullable();
            $table->text('description_en')->nullable();
            $table->foreignId('active_version_id')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'definition_key']);
            $table->index(['organization_id', 'is_available']);
        });

        Schema::create('survey_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('survey_definition_id');
            $table->unsignedInteger('version');
            $table->string('status', 24)->default('draft');
            $table->string('title', 200);
            $table->string('title_en', 200)->nullable();
            $table->text('description')->nullable();
            $table->text('description_en')->nullable();
            $table->json('definition');
            $table->json('scoring');
            $table->string('metric_schema_key', 120)->nullable();
            $table->string('source_reference', 500)->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'survey_definition_id', 'id']);
            $table->unique(['organization_id', 'survey_definition_id', 'version']);
            $table->foreign(['organization_id', 'survey_definition_id'])
                ->references(['organization_id', 'id'])
                ->on('survey_definitions')
                ->restrictOnDelete();
            $table->index(['organization_id', 'status', 'published_at']);
        });

        Schema::table('survey_definitions', function (Blueprint $table): void {
            $table->foreign(['organization_id', 'active_version_id'])
                ->references(['organization_id', 'id'])
                ->on('survey_versions')
                ->restrictOnDelete();
        });

        Schema::create('survey_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('survey_definition_id');
            $table->foreignId('survey_version_id');
            $table->string('status', 24)->default('in_progress');
            $table->text('definition_snapshot');
            $table->text('answers_snapshot')->nullable();
            $table->text('scoring_snapshot');
            $table->text('result_snapshot')->nullable();
            $table->string('metric_schema_key', 120)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'client_id', 'id']);
            $table->unique(['organization_id', 'client_id', 'survey_version_id', 'id']);
            $table->foreign(['organization_id', 'client_id'])->references(['organization_id', 'id'])->on('clients')->restrictOnDelete();
            $table->foreign(['organization_id', 'survey_definition_id'])->references(['organization_id', 'id'])->on('survey_definitions')->restrictOnDelete();
            $table->foreign(['organization_id', 'survey_definition_id', 'survey_version_id'])->references(['organization_id', 'survey_definition_id', 'id'])->on('survey_versions')->restrictOnDelete();
            $table->index(['organization_id', 'client_id', 'status', 'started_at']);
            $table->index(['organization_id', 'survey_definition_id', 'client_id', 'completed_at']);
        });

        Schema::create('survey_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('survey_attempt_id');
            $table->foreignId('survey_version_id');
            $table->string('title', 200);
            $table->text('report_snapshot');
            $table->timestampTz('materialized_at');
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'survey_attempt_id']);
            $table->foreign(['organization_id', 'client_id'])->references(['organization_id', 'id'])->on('clients')->restrictOnDelete();
            $table->foreign(['organization_id', 'client_id', 'survey_version_id', 'survey_attempt_id'])->references(['organization_id', 'client_id', 'survey_version_id', 'id'])->on('survey_attempts')->restrictOnDelete();
            $table->index(['organization_id', 'client_id', 'materialized_at']);
        });

        Schema::create('survey_comparisons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('previous_attempt_id');
            $table->foreignId('current_attempt_id');
            $table->string('status', 40);
            $table->text('comparison_snapshot');
            $table->foreignId('scenario_event_id')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'current_attempt_id']);
            $table->foreign(['organization_id', 'client_id'])->references(['organization_id', 'id'])->on('clients')->restrictOnDelete();
            $table->foreign(['organization_id', 'client_id', 'previous_attempt_id'])->references(['organization_id', 'client_id', 'id'])->on('survey_attempts')->restrictOnDelete();
            $table->foreign(['organization_id', 'client_id', 'current_attempt_id'])->references(['organization_id', 'client_id', 'id'])->on('survey_attempts')->restrictOnDelete();
            $table->foreign(['organization_id', 'scenario_event_id'])->references(['organization_id', 'id'])->on('scenario_events')->restrictOnDelete();
            $table->index(['organization_id', 'client_id', 'created_at']);
        });

        DB::statement("ALTER TABLE survey_versions ADD CONSTRAINT survey_versions_status_check CHECK (status IN ('draft', 'published', 'retired'))");
        DB::statement("ALTER TABLE survey_attempts ADD CONSTRAINT survey_attempts_status_check CHECK (status IN ('in_progress', 'completed'))");
        DB::statement("ALTER TABLE survey_comparisons ADD CONSTRAINT survey_comparisons_status_check CHECK (status IN ('not_comparable', 'improved', 'changed', 'stagnation_detected'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('survey_comparisons');
        Schema::dropIfExists('survey_reports');
        Schema::dropIfExists('survey_attempts');
        Schema::table('survey_definitions', function (Blueprint $table): void {
            $table->dropForeign(['organization_id', 'active_version_id']);
        });
        Schema::dropIfExists('survey_versions');
        Schema::dropIfExists('survey_definitions');
    }
};
