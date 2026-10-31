<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_tag_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('classification_run_id')->constrained('analytics_classification_runs')->restrictOnDelete();
            $table->foreignId('client_id')->nullable();
            $table->string('taxonomy', 40);
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->string('candidate_type', 16);
            $table->foreignId('suggested_existing_id')->nullable();
            $table->string('label', 255);
            $table->string('reviewed_label', 255)->nullable();
            $table->string('status', 24)->default('needs_review');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->unique(['organization_id', 'classification_run_id', 'candidate_type', 'label'], 'analytics_tag_candidate_unique');
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->nullOnDelete();
            $table->foreign(['organization_id', 'classification_run_id'])
                ->references(['organization_id', 'id'])
                ->on('analytics_classification_runs')
                ->cascadeOnDelete();
            $table->index(['organization_id', 'status', 'taxonomy']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_tag_candidates');
    }
};
