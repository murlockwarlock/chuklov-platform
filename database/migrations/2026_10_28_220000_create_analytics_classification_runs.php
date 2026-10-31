<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_classification_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable();
            $table->string('taxonomy', 40);
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->char('source_digest', 64);
            $table->string('status', 24)->default('processing');
            $table->unsignedSmallInteger('attempt_count')->default(1);
            $table->foreignId('ai_run_id')->nullable();
            $table->json('suggestions')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message_sanitized')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'taxonomy', 'source_type', 'source_id', 'source_digest'], 'analytics_classification_source_unique');
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->nullOnDelete();
            $table->foreign(['organization_id', 'ai_run_id'])
                ->references(['organization_id', 'id'])
                ->on('ai_runs')
                ->nullOnDelete();
            $table->index(['organization_id', 'taxonomy', 'status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_classification_runs');
    }
};
