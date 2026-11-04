<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_themes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 255);
            $table->string('group_name', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['organization_id', 'name']);
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'is_active', 'sort_order']);
        });

        Schema::create('feedback_submission_themes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('feedback_submission_id');
            $table->foreignId('feedback_theme_id');
            $table->timestampsTz();
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'feedback_submission_id'])
                ->references(['organization_id', 'id'])
                ->on('feedback_submissions')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'feedback_theme_id'])
                ->references(['organization_id', 'id'])
                ->on('feedback_themes')
                ->restrictOnDelete();
            $table->unique(
                ['organization_id', 'feedback_submission_id', 'feedback_theme_id'],
                'feedback_submission_themes_unique',
            );
            $table->index(['organization_id', 'client_id', 'feedback_theme_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE feedback_submissions DROP CONSTRAINT IF EXISTS feedback_submissions_score_check');
            DB::statement('ALTER TABLE feedback_submissions ADD CONSTRAINT feedback_submissions_score_check CHECK (score BETWEEN 0 AND 10)');
        }
    }

    public function down(): void
    {
        throw new LogicException('Feedback themes are retained by forward-only migrations.');
    }
};
